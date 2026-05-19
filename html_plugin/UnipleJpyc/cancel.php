<?php
/*
 * uniple checkout — cancelUrl 着地点 for EC-CUBE 2.x
 *
 * uniple Hosted Checkout でキャンセルされた user の戻り先。
 * 注文 status は webhook (= checkout.session.canceled / failed) で別途 ORDER_CANCEL に
 * 同期されるが、ここは即時 UI 戻り (= shopping/payment へ戻して flash 表示)。
 */

require_once realpath(dirname(__FILE__) . '/../../require.php');
require_once realpath(dirname(__FILE__) . '/../../../data/downloads/plugin/UnipleJpyc/lib/UnipleJpyc_Client.php');

$orderId = isset($_GET['orderId']) ? (int) $_GET['orderId'] : 0;

UnipleJpyc_Client::printLog('[uniple-cancel] order_id=' . $orderId);

// 標準 cart へ戻す (= shopping/payment より cart の方が再 checkout 動線として自然)
$objResponse = new SC_Response_Ex();
$objResponse->sendRedirect(CART_URLPATH, array('uniple_canceled' => '1'), true);
exit;
