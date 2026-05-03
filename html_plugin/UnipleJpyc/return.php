<?php
/*
 * uniple JPYC Checkout — successUrl 着地点 for EC-CUBE 2.x
 *
 * uniple Hosted Checkout 完走後の戻り先。webhook が正本なので、ここは UI 上の戻り。
 *   - mapping.status === 'completed' → shopping/complete
 *   - 未到着 / pending → 入金確認中ページ (= 標準 complete に簡易メッセージで戻す)
 *
 * Codex 推奨: SC_Response_Ex::sendRedirect で URL 直書き避ける
 */

require_once realpath(dirname(__FILE__) . '/../../require.php');

$objQuery = SC_Query_Ex::getSingletonInstance();

$sessionId = isset($_GET['sessionId']) ? (string) $_GET['sessionId'] : '';
$orderId = isset($_GET['orderId']) ? (int) $_GET['orderId'] : 0;

$mapping = null;
if ($sessionId !== '') {
    $mapping = $objQuery->getRow('id, order_id, status', 'plg_uniple_jpyc_intent_mapping', 'session_id = ?', array($sessionId));
}

// SC_Response_Ex で安全な redirect (= URL 直書き避ける、Codex 推奨)
$objResponse = new SC_Response_Ex();

if ($mapping && $mapping['status'] === 'completed') {
    // 入金済 → 標準の注文完了ページへ
    GC_Utils_Ex::gfPrintLog('[uniple-return] complete order_id=' . $mapping['order_id'] . ' sessionId=' . $sessionId, 'uniple_return.log');
    $objResponse->sendRedirect(SHOPPING_COMPLETE_URLPATH, array(), true);
    exit;
}

// webhook 未到着 or pending → 注文完了ページに pending パラメータ付きで戻す
// (= 標準 complete page で「入金確認中」を表示するか、別 template に飛ばすかは後 phase で UX 調整)
GC_Utils_Ex::gfPrintLog('[uniple-return] pending order_id=' . $orderId . ' sessionId=' . $sessionId, 'uniple_return.log');
$objResponse->sendRedirect(SHOPPING_COMPLETE_URLPATH, array('uniple_pending' => '1'), true);
exit;
