<?php
/*
 * uniple checkout for EC-CUBE 2
 * Copyright (C) 2026 uniple inc.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */
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
    UnipleJpyc_Client::printLog('[uniple-webhook] webhook_secret_not_configured');
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
    UnipleJpyc_Client::printLog('[uniple-webhook] invalid_signature sigPrefix=' . $sigPrefix . ' bytes=' . strlen($rawBody));
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'invalid_signature'));
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    UnipleJpyc_Client::printLog('[uniple-webhook] invalid_json bytes=' . strlen($rawBody));
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
    UnipleJpyc_Client::printLog('[uniple-webhook] duplicate event=' . $event . ' sessionId=' . UnipleJpyc_Client::maskToken($sessionId));
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
        UnipleJpyc_Client::printLog('[uniple-webhook] race_duplicate event=' . $event . ' sessionId=' . UnipleJpyc_Client::maskToken($sessionId));
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

// handler 成功時だけ processed_at を記録。失敗は retry で再処理できるように残す。
$logUpdate = array('http_status' => $httpStatus);
if ($httpStatus >= 200 && $httpStatus < 300) {
    $logUpdate['processed_at'] = $nowDateTime;
}
$objQuery->update('plg_uniple_jpyc_webhook_log', $logUpdate, 'idempotency_key = ?', array($idempotencyKey));

http_response_code($httpStatus);
echo json_encode($response);
exit;

// ---- handlers (= 4 系 UnipleWebhookController から関数化して移植) ----

function handleCompleted(SC_Query_Ex $objQuery, UnipleJpyc_Client $client, $sessionId, $amountRaw)
{
    $mapping = $objQuery->getRow('*', 'plg_uniple_jpyc_intent_mapping', 'session_id = ?', array($sessionId));
    if (!$mapping) {
        UnipleJpyc_Client::printLog('[uniple-webhook] mapping_not_found sessionId=' . UnipleJpyc_Client::maskToken($sessionId));
        return array(200, array('ok' => true, 'warning' => 'mapping_not_found'));
    }

    // 金額検証 (整数完全一致、amount 不在/不正は完了扱いしない)
    try {
        $amountInt = $client->toIntegerJpyc($amountRaw);
    } catch (Exception $e) {
        UnipleJpyc_Client::printLog('[uniple-webhook] amount_missing_or_invalid sessionId=' . UnipleJpyc_Client::maskToken($sessionId) . ' error=' . $e->getMessage());
        return array(400, array('ok' => false, 'error' => 'amount_missing_or_invalid'));
    }
    try {
        $expectedAmount = $client->toIntegerJpyc($mapping['amount_jpyc']);
    } catch (Exception $e) {
        UnipleJpyc_Client::printLog('[uniple-webhook] mapping_amount_invalid orderId=' . $mapping['order_id'] . ' sessionId=' . UnipleJpyc_Client::maskToken($sessionId) . ' error=' . $e->getMessage());
        return array(500, array('ok' => false, 'error' => 'mapping_amount_invalid'));
    }
    if ((string) $amountInt !== (string) $expectedAmount) {
        UnipleJpyc_Client::printLog('[uniple-webhook] amount_mismatch expected=' . $expectedAmount . ' actual=' . $amountInt . ' sessionId=' . UnipleJpyc_Client::maskToken($sessionId));
        return array(400, array('ok' => false, 'error' => 'amount_mismatch'));
    }

    $statusResult = syncOrderStatusPreEnd($mapping, $sessionId);
    if (!$statusResult[0]) {
        return array(500, array('ok' => false, 'error' => $statusResult[1]));
    }

    if ($mapping['status'] === 'completed') {
        return array(200, array('ok' => true, 'duplicate' => true));
    }

    // 注文 status 同期が成功した後に mapping completed 化する。
    $objQuery->update('plg_uniple_jpyc_intent_mapping', array(
        'status'       => 'completed',
        'completed_at' => date('Y-m-d H:i:s'),
    ), 'id = ?', array($mapping['id']));

    return array(200, array('ok' => true));
}

function syncOrderStatusPreEnd($mapping, $sessionId)
{
    // 注文 status を ORDER_PRE_END (= 入金済み、定数 6) へ同期
    // SC_Helper_Purchase::sfUpdateOrderStatus は notification handle 含む安全 path
    if (defined('ORDER_PRE_END') && class_exists('SC_Helper_Purchase_Ex')) {
        try {
            SC_Helper_Purchase_Ex::sfUpdateOrderStatus(
                (int) $mapping['order_id'],
                ORDER_PRE_END
            );
            UnipleJpyc_Client::printLog('[uniple-webhook] order_status_updated_to_pre_end orderId=' . $mapping['order_id'] . ' sessionId=' . UnipleJpyc_Client::maskToken($sessionId));
            return array(true, '');
        } catch (Exception $e) {
            UnipleJpyc_Client::printLog('[uniple-webhook] order_status_update_failed orderId=' . $mapping['order_id'] . ' error=' . $e->getMessage());
            // status 更新失敗は 5xx で uniple 側 retry させる (= 4 系設計と同方針)
            return array(false, 'order_status_update_failed');
        }
    }

    UnipleJpyc_Client::printLog('[uniple-webhook] order_status_helper_unavailable orderId=' . $mapping['order_id']);
    return array(true, '');
}

function handleCanceled(SC_Query_Ex $objQuery, $sessionId, $event)
{
    $mapping = $objQuery->getRow('*', 'plg_uniple_jpyc_intent_mapping', 'session_id = ?', array($sessionId));
    if (!$mapping) {
        return array(200, array('ok' => true, 'warning' => 'mapping_not_found'));
    }
    if ($mapping['status'] === 'completed') {
        UnipleJpyc_Client::printLog('[uniple-webhook] cancel_after_completed sessionId=' . UnipleJpyc_Client::maskToken($sessionId));
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
            UnipleJpyc_Client::printLog('[uniple-webhook] order_status_updated_to_cancel orderId=' . $mapping['order_id'] . ' newStatus=' . $newStatus . ' sessionId=' . UnipleJpyc_Client::maskToken($sessionId));
        } catch (Exception $e) {
            UnipleJpyc_Client::printLog('[uniple-webhook] order_cancel_failed orderId=' . $mapping['order_id'] . ' error=' . $e->getMessage());
            return array(500, array('ok' => false, 'error' => 'order_cancel_failed'));
        }
    }

    return array(200, array('ok' => true));
}
