<?php
/*
 * uniple JPYC Checkout — main plugin class for EC-CUBE 2.x
 *
 * SC_Plugin_Base 継承、register() hook で本体に決済 method を注入する。
 *
 * MVP scope:
 *   - 設定保存 (= API key + Webhook secret + merchant label)
 *   - 決済選択肢として「uniple JPYC ウォレット」を表示
 *   - 注文確定 → uniple Hosted Checkout (?wc=1) へ外部 redirect
 *   - webhook (= html/plugin/UnipleJpyc/webhook.php) で完走確認 → 注文 status 更新
 *
 * 法令準拠 (= JPYC は電子決済手段、資金決済法第 2 条第 5 項):
 *   - 「暗号資産」表記禁止
 *   - admin 設定画面に法令分類 + presskit 必須 3 行免責表記
 */

class UnipleJpyc extends SC_Plugin_Base
{
    /**
     * インストール時。
     * dtb_plugin に row が作成された後に呼ばれる。
     * 設定テーブル (= plg_uniple_jpyc_config / webhook_log / intent_mapping) を作成。
     */
    public static function install($arrPlugin, $objPluginInstaller = null)
    {
        $sqlPath = realpath(dirname(__FILE__) . '/sql/install.sql');
        if ($sqlPath && file_exists($sqlPath)) {
            $objQuery = SC_Query_Ex::getSingletonInstance();
            $sql = file_get_contents($sqlPath);
            // 複数文を分割実行
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                if ($stmt !== '') {
                    $objQuery->query($stmt);
                }
            }
        }
    }

    /**
     * アンインストール時。
     * MVP は table を残置 (= 設定 + log + mapping を保護)。
     * 完全削除したい場合は uninstall.sql を別途用意。
     */
    public static function uninstall($arrPlugin, $objPluginInstaller = null)
    {
        // no-op (= データ保護)
    }

    /**
     * 有効化時。
     * 必要に応じて Config singleton row を初期化。
     */
    public static function enable($arrPlugin, $objPluginInstaller = null)
    {
        $objQuery = SC_Query_Ex::getSingletonInstance();
        $count = (int) $objQuery->count('plg_uniple_jpyc_config', 'id = ?', array(1));
        if ($count === 0) {
            $objQuery->insert('plg_uniple_jpyc_config', array(
                'id'              => 1,
                'api_key'         => '',
                'webhook_secret'  => '',
                'merchant_label'  => '',
                'api_base_url'    => 'https://uniple.io',
                'mode'            => 'live',
                'create_date'     => 'CURRENT_TIMESTAMP',
                'update_date'     => 'CURRENT_TIMESTAMP',
            ));
        }
    }

    /**
     * 無効化時。
     */
    public static function disable($arrPlugin, $objPluginInstaller = null)
    {
        // no-op
    }

    /**
     * register hook。
     * 各種フックポイント (= 決済選択 / 注文確定 / 注文一覧画面 等) を登録。
     *
     * @param  SC_Helper_Plugin $objHelperPlugin EC-CUBE のフック helper
     */
    public function register(SC_Helper_Plugin $objHelperPlugin, $priority = null)
    {
        // フックポイント登録は Phase 2 で実装 (= LC_Page_Shopping_Payment hook、
        // admin 設定画面 hook、注文一覧 hook 等)。
        // 現段階は plugin install + 設定 table 作成のみ動作確認可能。
    }
}
