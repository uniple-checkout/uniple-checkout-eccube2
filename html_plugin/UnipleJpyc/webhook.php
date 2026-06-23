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
$clientReferenceId = isset($data['clientReferenceId']) ? (string) $data['clientReferenceId']
    : (isset($data['client_reference_id']) ? (string) $data['client_reference_id'] : '');
$merchantOrderId = isset($data['merchantOrderId']) ? (string) $data['merchantOrderId']
    : (isset($data['merchant_order_id']) ? (string) $data['merchant_order_id'] : '');
$productSku = isset($data['productSku']) ? (string) $data['productSku']
    : (isset($data['product_sku']) ? (string) $data['product_sku'] : '');
$amountRaw = isset($data['amountJpyc']) ? $data['amountJpyc']
    : (isset($data['amount_jpyc']) ? $data['amount_jpyc'] : '');
$isCompletedEvent = $event === 'checkout.session.completed' || $event === 'checkout.completed';
$normalMapping = $sessionId !== ''
    ? $objQuery->getRow('id', 'plg_uniple_jpyc_intent_mapping', 'session_id = ?', array($sessionId))
    : null;
$isX402Completed = $isCompletedEvent && $productSku !== '' && (!$normalMapping);

if ($event === '' || ($sessionId === '' && !$isX402Completed)) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'missing_required_field'));
    exit;
}

$idempotencyRef = $sessionId !== ''
    ? $sessionId
    : ($merchantOrderId !== ''
        ? $merchantOrderId
        : ($clientReferenceId !== '' ? $clientReferenceId : hash('sha256', $rawBody)));
if (strlen($idempotencyRef) > 180) {
    $idempotencyRef = hash('sha256', $idempotencyRef);
}
$idempotencyKey = $event . ':' . $idempotencyRef;
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
    if ($isX402Completed) {
        list($httpStatus, $response) = handleX402Completed($objQuery, $data, $idempotencyRef);
    } else {
        list($httpStatus, $response) = handleCompleted($objQuery, $client, $sessionId, $amountRaw);
    }
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

function handleX402Completed(SC_Query_Ex $objQuery, array $data, $idempotencyRef)
{
    $productSku = readPayloadString($data, array('productSku', 'product_sku'));
    $amount = safeToOrderAmount(isset($data['amountJpyc']) ? $data['amountJpyc'] : (isset($data['amount_jpyc']) ? $data['amount_jpyc'] : null));
    if ($productSku === '' || $amount === null) {
        UnipleJpyc_Client::printLog('[uniple-webhook] x402_missing_required_field productSku=' . $productSku);
        return array(400, array('ok' => false, 'error' => 'x402_missing_required_field'));
    }

    $product = findX402ProductClass($objQuery, $productSku);
    if (!$product) {
        UnipleJpyc_Client::printLog('[uniple-webhook] x402_product_not_found productSku=' . $productSku);
        return array(404, array('ok' => false, 'error' => 'product_not_found'));
    }

    $orderTempId = 'x402-' . substr(hash('sha256', $idempotencyRef), 0, 40);
    $existing = $objQuery->getRow('order_id', 'dtb_order', 'order_temp_id = ?', array($orderTempId));
    if ($existing && isset($existing['order_id'])) {
        return array(200, array('ok' => true, 'duplicate' => true, 'orderId' => (int) $existing['order_id']));
    }

    $payer = readPayloadString($data, array('payer', 'from'));
    $merchantOrderId = readPayloadString($data, array('merchantOrderId', 'merchant_order_id'));
    $clientReferenceId = readPayloadString($data, array('clientReferenceId', 'client_reference_id'));
    $txHash = readPayloadString($data, array('txHash', 'tx_hash', 'transactionId', 'transaction_id'));
    $itemName = readPayloadString($data, array('itemName', 'item_name'));
    if ($itemName === '') {
        $itemName = (string) $product['name'];
    }
    $shipping = x402ShippingAddress($objQuery, $data, $payer);
    $email = readPayloadString($data, array('email', 'buyerEmail', 'buyer_email', 'payerEmail', 'payer_email'));
    if ($email === '') {
        $email = $shipping['email'];
    }
    if ($email === '') {
        $email = 'x402-agent@uniple.local';
    }

    $payment = $objQuery->getRow(
        'payment_id, payment_method',
        'dtb_payment',
        'payment_method LIKE ? OR module_path LIKE ? ORDER BY payment_id DESC',
        array('%uniple%', '%UnipleJpyc%')
    );
    $paymentId = $payment && isset($payment['payment_id']) ? (int) $payment['payment_id'] : 0;
    $paymentMethod = $payment && isset($payment['payment_method']) && $payment['payment_method'] !== ''
        ? (string) $payment['payment_method']
        : 'JPYC決済 (uniple checkout)';

    $orderParams = array(
        'order_temp_id'    => $orderTempId,
        'customer_id'      => 0,
        'order_name01'     => $shipping['name01'],
        'order_name02'     => $shipping['name02'],
        'order_kana01'     => $shipping['kana01'],
        'order_kana02'     => $shipping['kana02'],
        'order_email'      => $email,
        'order_tel01'      => $shipping['tel01'],
        'order_tel02'      => $shipping['tel02'],
        'order_tel03'      => $shipping['tel03'],
        'order_zip01'      => $shipping['zip01'],
        'order_zip02'      => $shipping['zip02'],
        'order_zipcode'    => $shipping['zipcode'],
        'order_country_id' => 392,
        'order_pref'       => $shipping['pref'],
        'order_addr01'     => $shipping['addr01'],
        'order_addr02'     => $shipping['addr02'],
        'subtotal'         => $amount,
        'discount'         => 0,
        'deliv_fee'        => 0,
        'charge'           => 0,
        'use_point'        => 0,
        'add_point'        => 0,
        'tax'              => 0,
        'total'            => $amount,
        'payment_total'    => $amount,
        'payment_id'       => $paymentId,
        'payment_method'   => $paymentMethod,
        'note'             => x402OrderNote($productSku, $merchantOrderId, $clientReferenceId, $txHash, $payer),
        'status'           => defined('ORDER_PRE_END') ? ORDER_PRE_END : 6,
        'payment_date'     => 'CURRENT_TIMESTAMP',
        'device_type_id'   => 10,
        'del_flg'          => 0,
        'memo01'           => 'uniple x402',
        'memo02'           => $productSku,
        'memo03'           => $txHash,
    );

    try {
        $objQuery->begin();
        $orderId = SC_Helper_Purchase_Ex::registerOrder(null, $orderParams);
        SC_Helper_Purchase_Ex::registerOrderDetail($orderId, array(array(
            'order_id'             => $orderId,
            'product_id'           => (int) $product['product_id'],
            'product_class_id'     => (int) $product['product_class_id'],
            'product_name'         => $itemName,
            'product_code'         => (string) $product['product_code'],
            'classcategory_name1'  => (string) $product['classcategory_name1'],
            'classcategory_name2'  => (string) $product['classcategory_name2'],
            'price'                => $amount,
            'quantity'             => 1,
            'point_rate'           => 0,
            'tax_rate'             => 0,
            'tax_rule'             => 0,
        )));
        $objQuery->insert('dtb_shipping', array(
            'shipping_id'         => 0,
            'order_id'            => $orderId,
            'shipping_name01'     => $shipping['name01'],
            'shipping_name02'     => $shipping['name02'],
            'shipping_kana01'     => $shipping['kana01'],
            'shipping_kana02'     => $shipping['kana02'],
            'shipping_tel01'      => $shipping['tel01'],
            'shipping_tel02'      => $shipping['tel02'],
            'shipping_tel03'      => $shipping['tel03'],
            'shipping_country_id' => 392,
            'shipping_pref'       => $shipping['pref'],
            'shipping_zip01'      => $shipping['zip01'],
            'shipping_zip02'      => $shipping['zip02'],
            'shipping_zipcode'    => $shipping['zipcode'],
            'shipping_addr01'     => $shipping['addr01'],
            'shipping_addr02'     => $shipping['addr02'],
            'create_date'         => 'CURRENT_TIMESTAMP',
            'update_date'         => 'CURRENT_TIMESTAMP',
            'del_flg'             => 0,
        ));
        $objQuery->insert('dtb_shipment_item', array(
            'shipping_id'         => 0,
            'product_class_id'    => (int) $product['product_class_id'],
            'order_id'            => $orderId,
            'product_name'        => $itemName,
            'product_code'        => (string) $product['product_code'],
            'classcategory_name1' => (string) $product['classcategory_name1'],
            'classcategory_name2' => (string) $product['classcategory_name2'],
            'price'               => $amount,
            'quantity'            => 1,
        ));
        $objQuery->commit();
    } catch (Exception $e) {
        $objQuery->rollback();
        UnipleJpyc_Client::printLog('[uniple-webhook] x402_order_creation_failed productSku=' . $productSku . ' error=' . $e->getMessage());
        return array(500, array('ok' => false, 'error' => 'x402_order_creation_failed'));
    }

    UnipleJpyc_Client::printLog('[uniple-webhook] x402_order_created orderId=' . $orderId . ' productSku=' . $productSku);

    return array(200, array('ok' => true, 'x402' => true, 'orderId' => (int) $orderId));
}

function findX402ProductClass(SC_Query_Ex $objQuery, $productSku)
{
    if (!preg_match('/^eccube2-product-(\d+)-class-(\d+)$/', (string) $productSku, $m)) {
        return null;
    }

    $columns = implode(', ', array(
        'p.product_id',
        'p.name',
        'pc.product_class_id',
        'pc.product_code',
        'pc.classcategory_id1',
        'pc.classcategory_id2',
        'COALESCE(cc1.name, \'\') AS classcategory_name1',
        'COALESCE(cc2.name, \'\') AS classcategory_name2',
    ));
    $from = 'dtb_products p'
        . ' INNER JOIN dtb_products_class pc ON p.product_id = pc.product_id'
        . ' LEFT JOIN dtb_classcategory cc1 ON pc.classcategory_id1 = cc1.classcategory_id'
        . ' LEFT JOIN dtb_classcategory cc2 ON pc.classcategory_id2 = cc2.classcategory_id';

    return $objQuery->getRow(
        $columns,
        $from,
        'p.product_id = ? AND pc.product_class_id = ? AND p.del_flg = 0 AND pc.del_flg = 0',
        array((int) $m[1], (int) $m[2])
    );
}

function readPayloadString(array $data, array $keys)
{
    foreach ($keys as $key) {
        if (isset($data[$key]) && is_scalar($data[$key])) {
            return trim((string) $data[$key]);
        }
    }

    return '';
}

function readPayloadStringFrom(array $data, array $keys)
{
    return readPayloadString($data, $keys);
}

function x402ShippingAddress(SC_Query_Ex $objQuery, array $data, $payer)
{
    $shipping = x402ShippingPayload($data);
    list($fallbackName01, $fallbackName02) = x402BuyerName($data, $payer);

    $name01 = readPayloadStringFrom($shipping, array('name01', 'lastName', 'last_name', 'familyName', 'family_name'));
    $name02 = readPayloadStringFrom($shipping, array('name02', 'firstName', 'first_name', 'givenName', 'given_name'));
    $fullName = readPayloadStringFrom($shipping, array('name', 'fullName', 'full_name', 'recipientName', 'recipient_name', 'shippingName', 'shipping_name'));
    if (($name01 === '' || $name02 === '') && $fullName !== '') {
        $parts = preg_split('/\s+/u', $fullName, 2, PREG_SPLIT_NO_EMPTY);
        if ($name01 === '') {
            $name01 = isset($parts[0]) ? $parts[0] : '';
        }
        if ($name02 === '') {
            $name02 = isset($parts[1]) ? $parts[1] : '';
        }
    }
    if ($name01 === '') {
        $name01 = $fallbackName01;
    }
    if ($name02 === '') {
        $name02 = $fallbackName02;
    }

    $kana01 = readPayloadStringFrom($shipping, array('kana01', 'kanaLastName', 'kana_last_name'));
    $kana02 = readPayloadStringFrom($shipping, array('kana02', 'kanaFirstName', 'kana_first_name'));
    if ($kana01 === '') {
        $kana01 = 'エックス';
    }
    if ($kana02 === '') {
        $kana02 = 'バイヤー';
    }

    $city = readPayloadStringFrom($shipping, array('city', 'municipality', 'ward'));
    $line1 = readPayloadStringFrom($shipping, array('addr01', 'address1', 'address_1', 'addressLine1', 'address_line1', 'line1', 'streetAddress', 'street_address'));
    $addr01 = trim($city . ' ' . $line1);
    if ($addr01 === '') {
        $addr01 = 'x402';
    }
    $addr02 = readPayloadStringFrom($shipping, array('addr02', 'address2', 'address_2', 'addressLine2', 'address_line2', 'line2', 'building', 'apartment', 'room'));
    if ($addr02 === '') {
        $addr02 = 'AI purchase';
    }

    $tel = splitTel(readPayloadStringFrom($shipping, array('phoneNumber', 'phone_number', 'phone', 'tel', 'telephone')));
    $zip = splitZip(readPayloadStringFrom($shipping, array('postalCode', 'postal_code', 'postCode', 'post_code', 'zipCode', 'zip_code', 'zipcode', 'zip')));

    return array(
        'name01'  => mb_substr($name01, 0, 255),
        'name02'  => mb_substr($name02, 0, 255),
        'kana01'  => mb_substr($kana01, 0, 255),
        'kana02'  => mb_substr($kana02, 0, 255),
        'email'   => mb_substr(readPayloadStringFrom($shipping, array('email', 'mail')), 0, 255),
        'tel01'   => $tel[0],
        'tel02'   => $tel[1],
        'tel03'   => $tel[2],
        'zip01'   => $zip[0],
        'zip02'   => $zip[1],
        'zipcode' => $zip[0] . $zip[1],
        'pref'    => x402ResolvePref($objQuery, $shipping),
        'addr01'  => mb_substr($addr01, 0, 255),
        'addr02'  => mb_substr($addr02, 0, 255),
    );
}

function x402ShippingPayload(array $data)
{
    foreach (array('shipping', 'shippingAddress', 'shipping_address', 'delivery', 'recipient') as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            return $data[$key];
        }
    }

    return array();
}

function x402ResolvePref(SC_Query_Ex $objQuery, array $shipping)
{
    $prefId = readPayloadStringFrom($shipping, array('prefId', 'pref_id', 'prefectureId', 'prefecture_id'));
    if ($prefId !== '' && ctype_digit($prefId)) {
        $exists = $objQuery->getOne('SELECT id FROM mtb_pref WHERE id = ?', array((int) $prefId));
        if ($exists !== false && $exists !== null) {
            return (int) $prefId;
        }
    }

    $prefName = readPayloadStringFrom($shipping, array('pref', 'prefecture', 'state', 'province', 'region'));
    if ($prefName !== '') {
        $prefName = normalizePrefName($prefName);
        $id = $objQuery->getOne('SELECT id FROM mtb_pref WHERE name = ?', array($prefName));
        if ($id !== false && $id !== null) {
            return (int) $id;
        }
    }

    return 13;
}

function normalizePrefName($prefName)
{
    $prefName = trim((string) $prefName);
    $map = array(
        'tokyo' => '東京都',
        'tokyo-to' => '東京都',
        'osaka' => '大阪府',
        'osaka-fu' => '大阪府',
        'kyoto' => '京都府',
        'kyoto-fu' => '京都府',
        'hokkaido' => '北海道',
        'kanagawa' => '神奈川県',
        'saitama' => '埼玉県',
        'chiba' => '千葉県',
        'aichi' => '愛知県',
        'fukuoka' => '福岡県',
    );
    $key = strtolower(str_replace(array(' ', '_'), '-', $prefName));

    return isset($map[$key]) ? $map[$key] : $prefName;
}

function x402BuyerName(array $data, $payer)
{
    $raw = readPayloadString($data, array('buyerName', 'buyer_name', 'name'));
    if ($raw === '' && $payer !== '') {
        $raw = 'x402 ' . substr($payer, 0, 12);
    }
    if ($raw === '') {
        return array('x402', 'Buyer');
    }

    $parts = preg_split('/\s+/u', $raw, 2, PREG_SPLIT_NO_EMPTY);
    $name01 = isset($parts[0]) && $parts[0] !== '' ? mb_substr($parts[0], 0, 255) : 'x402';
    $name02 = isset($parts[1]) && $parts[1] !== '' ? mb_substr($parts[1], 0, 255) : 'Buyer';

    return array($name01, $name02);
}

function x402OrderNote($productSku, $merchantOrderId, $clientReferenceId, $txHash, $payer)
{
    $lines = array(
        'uniple x402 purchase',
        'productSku: ' . $productSku,
    );
    if ($merchantOrderId !== '') {
        $lines[] = 'merchantOrderId: ' . $merchantOrderId;
    }
    if ($clientReferenceId !== '') {
        $lines[] = 'clientReferenceId: ' . $clientReferenceId;
    }
    if ($txHash !== '') {
        $lines[] = 'txHash: ' . $txHash;
    }
    if ($payer !== '') {
        $lines[] = 'payer: ' . $payer;
    }

    return mb_substr(implode("\n", $lines), 0, 4000);
}

function splitTel($value)
{
    $digits = preg_replace('/\D+/', '', (string) $value);
    if ($digits === '') {
        $digits = '0000000000';
    }
    if (strlen($digits) === 10) {
        return array(substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 4));
    }

    return array(substr($digits, 0, 3), substr($digits, 3, 4), substr($digits, 7, 4));
}

function splitZip($value)
{
    $digits = preg_replace('/\D+/', '', (string) $value);
    if ($digits === '') {
        $digits = '0000000';
    }
    $digits = str_pad(substr($digits, 0, 7), 7, '0', STR_PAD_RIGHT);

    return array(substr($digits, 0, 3), substr($digits, 3, 4));
}

function safeToOrderAmount($value)
{
    if ($value === null || $value === '' || $value === false) {
        return null;
    }
    $s = trim((string) $value);
    if (!preg_match('/^(\d+)(?:\.(\d{1,6}))?$/', $s, $m)) {
        return null;
    }
    $integer = ltrim($m[1], '0');
    $integer = $integer === '' ? '0' : $integer;
    $fraction = isset($m[2]) ? rtrim($m[2], '0') : '';
    if ($integer === '0' && $fraction === '') {
        return null;
    }
    if (strlen($fraction) > 2) {
        return null;
    }

    return $fraction === '' ? $integer : $integer . '.' . $fraction;
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
