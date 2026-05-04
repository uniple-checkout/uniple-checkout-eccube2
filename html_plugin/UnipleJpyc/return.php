<?php
/*
 * uniple JPYC Checkout — successUrl 着地点 for EC-CUBE 2.x
 *
 * uniple Hosted Checkout 完走後の戻り先。webhook が正本なので、ここは UI 上の戻り。
 *   - mapping.status === 'completed' → shopping/complete + cart purge
 *   - 未到着 / pending → 入金確認中ページ (= 標準 complete に簡易メッセージで戻す)
 *
 * lookup 戦略 (= 2026-05-04 確定の uniple 真仕様に整合):
 *   primary: payment.php で $_SESSION['uniple_jpyc_pending_order_id'] に set した
 *           EC-CUBE 内部 order id を復元
 *   fallback: ?cs query (= uniple が完走時に append する Checkout Session ID)
 * uniple は successUrl に完走時 ?orderId=pay-sp_v3_... / ?cs=ucs_... / ?txHash 等
 * を append する。 ?orderId は uniple 側 ID で plugin の EC-CUBE 内部 ID と key 衝突
 * するため、 EC-CUBE 内部 ID は session 経路で渡す。
 * security: ログイン顧客は dtb_order.customer_id 一致 check (= 改竄対策)。
 *
 * Codex 推奨: SC_Response_Ex::sendRedirect で URL 直書き避ける
 */

require_once realpath(dirname(__FILE__) . '/../../require.php');

$objQuery = SC_Query_Ex::getSingletonInstance();

// primary: payment.php で $_SESSION に保存した EC-CUBE 内部 order id
$ecOrderId = isset($_SESSION['uniple_jpyc_pending_order_id']) ? (int) $_SESSION['uniple_jpyc_pending_order_id'] : 0;
// fallback: ?cs query で uniple Checkout Session ID
$unipleSessionId = isset($_GET['cs']) ? (string) $_GET['cs'] : '';

$mapping = null;
if ($ecOrderId > 0) {
    $mapping = $objQuery->getRow('id, order_id, status', 'plg_uniple_jpyc_intent_mapping', 'order_id = ?', array($ecOrderId));
}
if (!$mapping && $unipleSessionId !== '') {
    $mapping = $objQuery->getRow('id, order_id, status', 'plg_uniple_jpyc_intent_mapping', 'session_id = ?', array($unipleSessionId));
}

// SC_Response_Ex で安全な redirect (= URL 直書き避ける、Codex 推奨)
$objResponse = new SC_Response_Ex();

if ($mapping && $mapping['status'] === 'completed') {
    // ログイン顧客の場合は dtb_order.customer_id 一致 check (= 改竄対策)
    $authorized = true;
    $objCustomer = new SC_Customer_Ex();
    $customerId = (int) $objCustomer->getValue('customer_id');
    if ($customerId > 0) {
        $orderRow = $objQuery->getRow('customer_id', 'dtb_order', 'order_id = ?', array((int) $mapping['order_id']));
        if (!$orderRow || (int) $orderRow['customer_id'] !== $customerId) {
            $authorized = false;
        }
    }

    if ($authorized) {
        // 入金済 → cart を空にする (= EC-CUBE 標準の SC_Helper_Purchase::completeOrder 経路を
        // 通らない Hosted Checkout flow でも cart を残さないため)。
        // SC_CartSession::delAllProducts は商品種別 ID をキーに削除する設計、
        // 通常購入と同じく現在の cartKey で purge する。
        try {
            $objCartSession = new SC_CartSession_Ex();
            $cartKey = $objCartSession->getKey();
            if ($cartKey !== null && $cartKey !== '') {
                $objCartSession->delAllProducts($cartKey);
            }
        } catch (Exception $e) {
            GC_Utils_Ex::gfPrintLog('[uniple-return] cart_purge_failed order_id=' . $mapping['order_id'] . ' error=' . $e->getMessage(), 'uniple_return.log');
        }

        // LC_Page_Shopping_Complete::action() は $_SESSION['order_id'] を見て注文番号を表示する。
        // 通常 path では SC_Helper_Purchase::registerOrderComplete が set するが、
        // Hosted Checkout 経路ではそれを通らないため、 ここで session 復元する必要がある。
        // 標準 LC_Page_Shopping_Complete のコメント: 「プラグインなどで order_id を取得する場合があるため」
        $_SESSION['order_id'] = (int) $mapping['order_id'];

        // pending_order_id を再使用防止のため unset (= 二重 return 時は ?cs fallback 経路に倒れる)
        unset($_SESSION['uniple_jpyc_pending_order_id']);

        // 標準の注文完了ページへ
        GC_Utils_Ex::gfPrintLog('[uniple-return] complete order_id=' . $mapping['order_id'] . ' sessionId=' . $unipleSessionId, 'uniple_return.log');
        $objResponse->sendRedirect(SHOPPING_COMPLETE_URLPATH, array(), true);
        exit;
    }

    // 認可 fail (= 他人の order ID を踏もうとした) も session を破棄
    unset($_SESSION['uniple_jpyc_pending_order_id']);
    GC_Utils_Ex::gfPrintLog('[uniple-return] unauthorized order_id=' . $mapping['order_id'] . ' customer_id=' . $customerId, 'uniple_return.log');
}

// webhook 未到着 or pending → 注文完了ページに pending パラメータ付きで戻す
// (= 標準 complete page で「入金確認中」を表示するか、別 template に飛ばすかは後 phase で UX 調整)
GC_Utils_Ex::gfPrintLog('[uniple-return] pending ec_order_id=' . $ecOrderId . ' cs=' . $unipleSessionId, 'uniple_return.log');
$objResponse->sendRedirect(SHOPPING_COMPLETE_URLPATH, array('uniple_pending' => '1'), true);
exit;
