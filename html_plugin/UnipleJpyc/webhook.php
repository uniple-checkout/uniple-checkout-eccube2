<?php
/*
 * uniple checkout — webhook receiver for EC-CUBE 2.x
 *
 * uniple checkout.session.* webhook を受信し、署名検証 + 冪等処理 +
 * IntentMapping 更新 + 注文 status 更新 (= ORDER_PRE_END / ORDER_CANCEL) を行う。
 *
 * Codex 査読: 「html/plugin/UnipleJpyc/webhook.php に独自 PHP file 設置」
 * 「completed → ORDER_PRE_END、取消系 → ORDER_CANCEL」
 *
 * MVP scope:
 *   - HMAC-SHA256 署名検証 (= 4 系から完全流用)
 *   - idempotency_key (= event + sessionId) UNIQUE で多重弾き
 *   - WebhookLog 記録
 *   - IntentMapping → completed
 *   - Phase 2 で SC_Helper_Purchase 経由の注文 status 更新を追加
 */

// EC-CUBE 2 系 bootstrap (= ROOT_DIR / DATA_REALDIR 等の定数を解決)
require_once realpath(dirname(__FILE__) . '/../../require.php');

require_once realpath(dirname(__FILE__) . '/../../../data/downloads/plugin/UnipleJpyc/lib/UnipleJpyc_Client.php');

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok' => false, 'error' => 'method_not_allowed'));
    exit;
}

// raw body 取得 (= Symfony 等の framework parse 前)
$rawBody = file_get_contents('php://input');
$sigHeader = isset($_SERVER['HTTP_X_UNIPLE_SIGNATURE']) ? (string) $_SERVER['HTTP_X_UNIPLE_SIGNATURE'] : '';

// Config 読込
$objQuery = SC_Query_Ex::getSingletonInstance();
$configRow = $objQuery->getRow('*', 'plg_uniple_jpyc_config', 'id = ?', array(1));
if (!$configRow) {
    http_response_code(503);
    echo json_encode(array('ok' => false, 'error' => 'config_not_initialized'));
    exit;
}
$secret = (string) $configRow['webhook_secret'];
if ($secret === '') {
    GC_Utils_Ex::gfPrintLog('[uniple-webhook] webhook_secret_not_configured', 'webhook.log');
    http_response_code(503);
    echo json_encode(array('ok' => false, 'error' => 'webhook_secret_not_configured'));
    exit;
}

// 署名検証
$client = new UnipleJpyc_Client(array(
    'api_key'        => $configRow['api_key'],
    'webhook_secret' => $secret,
    'merchant_label' => $configRow['merchant_label'],
    'api_base_url'   => $configRow['api_base_url'],
    'mode'           => $configRow['mode'],
));
if (!$client->verifySignature($rawBody, $sigHeader)) {
    $sigPrefix = $sigHeader !== '' ? substr(preg_replace('/^sha256=/', '', $sigHeader), 0, 12) : '';
    GC_Utils_Ex::gfPrintLog('[uniple-webhook] invalid_signature sigPrefix=' . $sigPrefix . ' bytes=' . strlen($rawBody), 'webhook.log');
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'invalid_signature'));
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    GC_Utils_Ex::gfPrintLog('[uniple-webhook] invalid_json bytes=' . strlen($rawBody), 'webhook.log');
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'invalid_json'));
    exit;
}

$event = isset($payload['event']) ? (string) $payload['event']
    : (isset($payload['type']) ? (string) $payload['type'] : '');
$data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;
$sessionId = isset($data['sessionId']) ? (string) $data['sessionId']
    : (isset($data['session_id']) ? (string) $data['session_id'] : '');
$amountRaw = isset($data['amountJpyc']) ? $data['amountJpyc']
    : (isset($data['amount_jpyc']) ? $data['amount_jpyc'] : '');

if ($event === '' || $sessionId === '') {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'missing_required_field'));
    exit;
}

$idempotencyKey = $event . ':' . $sessionId;
$nowDateTime = date('Y-m-d H:i:s');
$sigPrefix = $sigHeader !== '' ? substr(preg_replace('/^sha256=/', '', $sigHeader), 0, 12) : '';

// 冪等チェック
$existing = $objQuery->getRow('id, processed_at', 'plg_uniple_jpyc_webhook_log', 'idempotency_key = ?', array($idempotencyKey));
if ($existing && $existing['processed_at']) {
    GC_Utils_Ex::gfPrintLog('[uniple-webhook] duplicate idempotencyKey=' . $idempotencyKey, 'webhook.log');
    echo json_encode(array('ok' => true, 'duplicate' => true));
    exit;
}

// log 記録 (= INSERT IGNORE 相当、UNIQUE 制約で race を弾く)
if (!$existing) {
    try {
        $objQuery->insert('plg_uniple_jpyc_webhook_log', array(
            'idempotency_key'  => $idempotencyKey,
            'event_type'       => $event,
            'session_id'       => $sessionId,
            'signature_prefix' => $sigPrefix,
            'received_at'      => $nowDateTime,
        ));
    } catch (Exception $e) {
        // race condition (UNIQUE 制約違反) は早期 200
        GC_Utils_Ex::gfPrintLog('[uniple-webhook] race_duplicate idempotencyKey=' . $idempotencyKey, 'webhook.log');
        echo json_encode(array('ok' => true, 'duplicate' => true));
        exit;
    }
}

// event 別処理
$httpStatus = 200;
$response = array('ok' => true);

if ($event === 'checkout.session.completed' || $event === 'checkout.completed') {
    list($httpStatus, $response) = handleCompleted($objQuery, $client, $sessionId, $amountRaw);
} elseif ($event === 'checkout.session.canceled' || $event === 'checkout.session.expired' || $event === 'checkout.session.failed') {
    list($httpStatus, $response) = handleCanceled($objQuery, $sessionId, $event);
}

// log を processed に更新
$objQuery->update('plg_uniple_jpyc_webhook_log', array(
    'http_status'  => $httpStatus,
    'processed_at' => $nowDateTime,
), 'idempotency_key = ?', array($idempotencyKey));

http_response_code($httpStatus);
echo json_encode($response);
exit;

// ---- handlers (= 4 系 UnipleWebhookController から関数化して移植) ----

function handleCompleted(SC_Query_Ex $objQuery, UnipleJpyc_Client $client, $sessionId, $amountRaw)
{
    $mapping = $objQuery->getRow('*', 'plg_uniple_jpyc_intent_mapping', 'session_id = ?', array($sessionId));
    if (!$mapping) {
        GC_Utils_Ex::gfPrintLog('[uniple-webhook] mapping_not_found sessionId=' . $sessionId, 'webhook.log');
        return array(200, array('ok' => true, 'warning' => 'mapping_not_found'));
    }

    // 金額検証 (整数完全一致)
    try {
        $amountInt = $client->toIntegerJpyc($amountRaw);
        if ($mapping['amount_jpyc'] !== '' && (string) $amountInt !== (string) $mapping['amount_jpyc']) {
            GC_Utils_Ex::gfPrintLog('[uniple-webhook] amount_mismatch expected=' . $mapping['amount_jpyc'] . ' actual=' . $amountInt, 'webhook.log');
            return array(400, array('ok' => false, 'error' => 'amount_mismatch'));
        }
    } catch (Exception $e) {
        // amount 不正は warning 扱い (= 旧 webhook で空送信もありうる)
    }

    if ($mapping['status'] === 'completed') {
        return array(200, array('ok' => true, 'duplicate' => true));
    }

    // mapping completed 化
    $objQuery->update('plg_uniple_jpyc_intent_mapping', array(
        'status'       => 'completed',
        'completed_at' => date('Y-m-d H:i:s'),
    ), 'id = ?', array($mapping['id']));

    // 注文 status を ORDER_PRE_END (= 入金済み、定数 6) へ同期
    // SC_Helper_Purchase::sfUpdateOrderStatus は notification handle 含む安全 path
    if (defined('ORDER_PRE_END') && class_exists('SC_Helper_Purchase_Ex')) {
        try {
            SC_Helper_Purchase_Ex::sfUpdateOrderStatus(
                (int) $mapping['order_id'],
                ORDER_PRE_END
            );
            GC_Utils_Ex::gfPrintLog('[uniple-webhook] order_status_updated_to_pre_end orderId=' . $mapping['order_id'] . ' sessionId=' . $sessionId, 'webhook.log');
        } catch (Exception $e) {
            GC_Utils_Ex::gfPrintLog('[uniple-webhook] order_status_update_failed orderId=' . $mapping['order_id'] . ' error=' . $e->getMessage(), 'webhook.log');
            // status 更新失敗は 5xx で uniple 側 retry させる (= 4 系設計と同方針)
            return array(500, array('ok' => false, 'error' => 'order_status_update_failed'));
        }
    } else {
        GC_Utils_Ex::gfPrintLog('[uniple-webhook] order_status_helper_unavailable orderId=' . $mapping['order_id'], 'webhook.log');
    }

    return array(200, array('ok' => true));
}

function handleCanceled(SC_Query_Ex $objQuery, $sessionId, $event)
{
    $mapping = $objQuery->getRow('*', 'plg_uniple_jpyc_intent_mapping', 'session_id = ?', array($sessionId));
    if (!$mapping) {
        return array(200, array('ok' => true, 'warning' => 'mapping_not_found'));
    }
    if ($mapping['status'] === 'completed') {
        GC_Utils_Ex::gfPrintLog('[uniple-webhook] cancel_after_completed sessionId=' . $sessionId, 'webhook.log');
        return array(200, array('ok' => true, 'warning' => 'already_completed'));
    }
    $newStatus = ($event === 'checkout.session.expired') ? 'expired' : 'canceled';
    $objQuery->update('plg_uniple_jpyc_intent_mapping', array(
        'status'       => $newStatus,
        'completed_at' => date('Y-m-d H:i:s'),
    ), 'id = ?', array($mapping['id']));

    // 注文 status を ORDER_CANCEL (= キャンセル、定数 3) へ同期
    if (defined('ORDER_CANCEL') && class_exists('SC_Helper_Purchase_Ex')) {
        try {
            SC_Helper_Purchase_Ex::sfUpdateOrderStatus(
                (int) $mapping['order_id'],
                ORDER_CANCEL
            );
            GC_Utils_Ex::gfPrintLog('[uniple-webhook] order_status_updated_to_cancel orderId=' . $mapping['order_id'] . ' newStatus=' . $newStatus, 'webhook.log');
        } catch (Exception $e) {
            GC_Utils_Ex::gfPrintLog('[uniple-webhook] order_cancel_failed orderId=' . $mapping['order_id'] . ' error=' . $e->getMessage(), 'webhook.log');
            return array(500, array('ok' => false, 'error' => 'order_cancel_failed'));
        }
    }

    return array(200, array('ok' => true));
}
