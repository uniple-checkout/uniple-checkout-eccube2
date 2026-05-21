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
 * uniple checkout — payment module for EC-CUBE 2.x
 *
 * `dtb_payment.module_path` にこの file の絶対 path を登録、`memo03` 非空に
 * すると、shopping/load_payment_module.php → SC_Helper_Payment::useModule()
 * 経由でこの file が require_once される。
 *
 * ここで:
 *   - $_SESSION から order_id 取得 (= LC_Page_Shopping_LoadPaymentModule::getOrderId)
 *   - dtb_order から amount + 注文情報引く
 *   - uniple session 作成 (= UnipleJpyc_Client::createSession)
 *   - IntentMapping (= plg_uniple_jpyc_intent_mapping) に order_id ↔ session_id 保存
 *   - uniple Hosted Checkout (= Phase 2 r22 で ?wc=1 削除、 経路は uniple SSR) へ header() redirect
 *
 * このファイルは LC_Page_Shopping_LoadPaymentModule の context で実行されるので
 * $_SESSION / SC_Query / その他 EC-CUBE bootstrap がすべて利用可能。
 */

require_once realpath(dirname(__FILE__) . '/../lib/UnipleJpyc_Client.php');

$objQuery = SC_Query_Ex::getSingletonInstance();

// load_payment_module.php が parent クラスで getOrderId() を呼んで $order_id を持っているはず。
// ただしこの module は module_path 経由で include されるため、関数 scope 外。
// $_SESSION['order_id'] か SC_Helper_Purchase 経由で取得する必要あり。
$order_id = isset($_SESSION['order_id']) ? (int) $_SESSION['order_id'] : 0;
if ($order_id === 0) {
    UnipleJpyc_Client::printLog('[uniple-payment] no order_id in session');
    SC_Utils_Ex::sfDispSiteError(PAGE_ERROR, '', true);
    return;
}

// 注文情報取得
$arrOrder = $objQuery->getRow('order_id, payment_total, order_name01, order_name02', 'dtb_order', 'order_id = ?', array($order_id));
if (!$arrOrder) {
    UnipleJpyc_Client::printLog('[uniple-payment] order_not_found order_id=' . $order_id);
    SC_Utils_Ex::sfDispSiteError(PAGE_ERROR, '', true);
    return;
}

$amount = (int) $arrOrder['payment_total'];
$itemName = 'Order #' . $order_id;
// 注文の最初の商品名で description を作る (= optional、失敗しても続行)
$arrFirstItem = $objQuery->getRow('product_name', 'dtb_order_detail', 'order_id = ? ORDER BY order_detail_id', array($order_id));
if ($arrFirstItem && !empty($arrFirstItem['product_name'])) {
    $itemName = $arrFirstItem['product_name'];
}

// Config 読込
$arrConfig = $objQuery->getRow('*', 'plg_uniple_jpyc_config', 'id = ?', array(1));
if (!$arrConfig || empty($arrConfig['api_key'])) {
    UnipleJpyc_Client::printLog('[uniple-payment] config not initialized or api_key empty');
    SC_Utils_Ex::sfDispSiteError(FREE_ERROR_MSG, '', true, 'uniple JPYC 決済が利用できません。管理者にお問い合わせください。');
    return;
}

// shop URL の組立 (= return / cancel / webhook)
// uniple は successUrl に完走時 ?orderId=pay-sp_v3_... / ?cs=ucs_... 等を append
// する仕様 (= placeholder 展開仕様は uniple 側にない、 2026-05-04 確定)。
// EC-CUBE 内部 order id は $_SESSION 経路で渡し、 uniple の ?orderId 上書きを回避する。
$shopBase = rtrim(HTTPS_URL, '/');
$merchantOrderId = sprintf('eccube2-%d-%s', $order_id, bin2hex(random_bytes(4)));
$successUrl = $shopBase . '/plugin/UnipleJpyc/return.php';
$cancelUrl  = $shopBase . '/plugin/UnipleJpyc/cancel.php?orderId=' . $order_id;
$webhookUrl = $shopBase . '/plugin/UnipleJpyc/webhook.php';

// uniple session 作成
try {
    $client = new UnipleJpyc_Client(array(
        'api_key'        => $arrConfig['api_key'],
        'webhook_secret' => $arrConfig['webhook_secret'],
        'merchant_label' => $arrConfig['merchant_label'],
        'api_base_url'   => $arrConfig['api_base_url'],
        'mode'           => $arrConfig['mode'],
    ));
    $session = $client->createSession(array(
        'amountJpyc'      => $amount,
        'merchantOrderId' => $merchantOrderId,
        'itemName'        => $itemName,
        'successUrl'      => $successUrl,
        'cancelUrl'       => $cancelUrl,
        'webhookUrl'      => $webhookUrl,
    ));
} catch (Exception $e) {
    UnipleJpyc_Client::printLog('[uniple-payment] session_create_failed order_id=' . $order_id . ' error=' . $e->getMessage());
    SC_Utils_Ex::sfDispSiteError(FREE_ERROR_MSG, '', true, 'uniple セッション作成に失敗しました。しばらくしてから再度お試しください。');
    return;
}

$checkoutUrl = (string) $session['checkoutUrl'];
if (!UnipleJpyc_Client::isAllowedUnipleOrigin($checkoutUrl)) {
    UnipleJpyc_Client::printLog('[uniple-payment] invalid_checkout_url order_id=' . $order_id . ' host=' . (parse_url($checkoutUrl, PHP_URL_HOST) ?: ''));
    SC_Utils_Ex::sfDispSiteError(FREE_ERROR_MSG, '', true, 'uniple セッション作成に失敗しました。しばらくしてから再度お試しください。');
    return;
}

// IntentMapping 保存
$objQuery->insert('plg_uniple_jpyc_intent_mapping', array(
    'order_id'    => $order_id,
    'session_id'  => $session['sessionId'],
    'amount_jpyc' => (string) $amount,
    'status'      => 'pending',
    'created_at'  => date('Y-m-d H:i:s'),
));

// EC-CUBE 内部 order id を $_SESSION に保存。 return.php 側で復元する。
// uniple の ?orderId append (= uniple 側 ID) で plugin の EC-CUBE 内部 ID が
// URL から読めないため、 session 経路で渡す。
$_SESSION['uniple_jpyc_pending_order_id'] = (int) $order_id;
$_SESSION['uniple_jpyc_pending_session_id'] = (string) $session['sessionId'];
$_SESSION['uniple_jpyc_pending_merchant_order_id'] = (string) $merchantOrderId;

UnipleJpyc_Client::printLog('[uniple-payment] session_created order_id=' . $order_id . ' sessionId=' . UnipleJpyc_Client::maskToken($session['sessionId']) . ' amount=' . $amount . ' merchantOrderId=' . UnipleJpyc_Client::maskToken($merchantOrderId));

// uniple Hosted Checkout へ外部 redirect (= 経路振り分けは uniple SSR で完結、 Phase 2 r22)
header('Location: ' . $checkoutUrl, true, 302);
exit;
