<?php
/*
 * uniple JPYC Checkout — successUrl 着地点 for EC-CUBE 2.x
 *
 * uniple Hosted Checkout 完走後の戻り先。webhook が正本なので、ここは UI 上の戻り。
 *   - mapping.status === 'completed' → shopping/complete + cart purge
 *   - 未到着 / pending → 入金確認中ページ (= 標準 complete に簡易メッセージで戻す)
 *
 * lookup 戦略: query の orderId で IntentMapping を逆引きする。
 * sessionId は uniple 側が `{CHECKOUT_SESSION_ID}` placeholder を展開して
 * URL に埋め込む仕様だが、現時点で uniple 側 placeholder 展開が動かない
 * 環境があり、sessionId が literal のまま戻る = sessionId lookup が常に
 * null を返す事象を観測したため、orderId 経路に切り替えた。
 * security: orderId 改竄対策として、ログイン顧客の場合は dtb_order.customer_id
 * 一致も check する。
 *
 * Codex 推奨: SC_Response_Ex::sendRedirect で URL 直書き避ける
 */

require_once realpath(dirname(__FILE__) . '/../../require.php');

$objQuery = SC_Query_Ex::getSingletonInstance();

$sessionId = isset($_GET['sessionId']) ? (string) $_GET['sessionId'] : '';
$orderId = isset($_GET['orderId']) ? (int) $_GET['orderId'] : 0;

// primary lookup: orderId 逆引き (= sessionId placeholder 未展開の場合に対応)
$mapping = null;
if ($orderId > 0) {
    $mapping = $objQuery->getRow('id, order_id, status', 'plg_uniple_jpyc_intent_mapping', 'order_id = ?', array($orderId));
}
// fallback: sessionId 経路 (= placeholder が将来展開された場合の path 維持)
if (!$mapping && $sessionId !== '' && $sessionId !== '{CHECKOUT_SESSION_ID}') {
    $mapping = $objQuery->getRow('id, order_id, status', 'plg_uniple_jpyc_intent_mapping', 'session_id = ?', array($sessionId));
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

        // 標準の注文完了ページへ
        GC_Utils_Ex::gfPrintLog('[uniple-return] complete order_id=' . $mapping['order_id'] . ' sessionId=' . $sessionId, 'uniple_return.log');
        $objResponse->sendRedirect(SHOPPING_COMPLETE_URLPATH, array(), true);
        exit;
    }

    GC_Utils_Ex::gfPrintLog('[uniple-return] unauthorized order_id=' . $mapping['order_id'] . ' customer_id=' . $customerId, 'uniple_return.log');
}

// webhook 未到着 or pending → 注文完了ページに pending パラメータ付きで戻す
// (= 標準 complete page で「入金確認中」を表示するか、別 template に飛ばすかは後 phase で UX 調整)
GC_Utils_Ex::gfPrintLog('[uniple-return] pending order_id=' . $orderId . ' sessionId=' . $sessionId, 'uniple_return.log');
$objResponse->sendRedirect(SHOPPING_COMPLETE_URLPATH, array('uniple_pending' => '1'), true);
exit;
