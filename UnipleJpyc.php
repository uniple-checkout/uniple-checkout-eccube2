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
 * uniple checkout — main plugin class for EC-CUBE 2.x
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
        self::ensureLogDirectory();

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
     *   - Config singleton row 初期化
     *   - dtb_payment に「uniple JPYC ウォレット」を冪等 INSERT (or del_flg=0 復活)
     *   - dtb_payment_options に全 deliv_id を bind (= 全配送方法で選択可能化)
     *
     * Codex 推奨: dtb_payment 登録 + memo03 非空 + module_path 設定で
     * SC_Helper_Payment::useModule() 経由 shopping/load_payment_module.php に流す
     */
    public static function enable($arrPlugin, $objPluginInstaller = null)
    {
        $objQuery = SC_Query_Ex::getSingletonInstance();
        self::ensureLogDirectory();

        // Config singleton
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

        // shim を MODULE_REALDIR/UnipleJpyc/payment.php に配置。
        // EC-CUBE 2 の LC_Page_Shopping_LoadPaymentModule は module_path を MODULE_REALDIR 配下に
        // 強制する仕様 (= MODULE_REALDIR + module_path を file_exists check) のため、本体は plugin 配下に置きつつ
        // MODULE_REALDIR には「本体 require だけの shim」を copy する。
        $shimDir = realpath(MODULE_REALDIR) . '/UnipleJpyc';
        if (!is_dir($shimDir)) {
            mkdir($shimDir, 0755, true);
        }
        $shimSrc = realpath(dirname(__FILE__) . '/module/payment_shim.php');
        $shimDst = $shimDir . '/payment.php';
        if ($shimSrc && file_exists($shimSrc)) {
            copy($shimSrc, $shimDst);
        }

        // dtb_payment 登録 (= 冪等)
        // module_path: MODULE_REALDIR 相対パス (= LoadPaymentModule の仕様に準拠)
        $modulePath = 'UnipleJpyc/payment.php';
        $existing = $objQuery->getRow('payment_id, del_flg', 'dtb_payment', 'module_path = ?', array($modulePath));
        if ($existing) {
            // 既存 row を復活 (= del_flg=0)
            $objQuery->update('dtb_payment', array(
                'del_flg'        => 0,
                'payment_method' => 'uniple JPYC ウォレット',
                'memo03'         => 'UnipleJpyc',
                'note'           => 'JPYC（日本円ステーブルコイン、電子決済手段）でお支払いいただけます。',
                'fix'            => 2,
                'rank'           => 99,
                'charge'         => 0,
                'charge_flg'     => 0,
                'status'         => 1,
                'update_date'    => 'CURRENT_TIMESTAMP',
            ), 'payment_id = ?', array($existing['payment_id']));
            $paymentId = (int) $existing['payment_id'];
        } else {
            // 新規 INSERT (= rank は自動付与せず固定 99 で末尾)
            $paymentId = $objQuery->nextVal('dtb_payment_payment_id');
            $objQuery->insert('dtb_payment', array(
                'payment_id'     => $paymentId,
                'payment_method' => 'uniple JPYC ウォレット',
                'memo03'         => 'UnipleJpyc',
                'module_path'    => $modulePath,
                'note'           => 'JPYC（日本円ステーブルコイン、電子決済手段）でお支払いいただけます。',
                'fix'            => 2,
                'rank'           => 99,
                'charge'         => 0,
                'charge_flg'     => 0,
                'status'         => 1,
                'del_flg'        => 0,
                'creator_id'     => 0,
                'create_date'    => 'CURRENT_TIMESTAMP',
                'update_date'    => 'CURRENT_TIMESTAMP',
            ));
        }

        // dtb_payment_options で全 deliv_id に bind
        $arrDeliv = $objQuery->select('deliv_id', 'dtb_deliv', 'del_flg = 0');
        foreach ($arrDeliv as $deliv) {
            $exists = $objQuery->count('dtb_payment_options', 'deliv_id = ? AND payment_id = ?', array($deliv['deliv_id'], $paymentId));
            if ($exists == 0) {
                $objQuery->insert('dtb_payment_options', array(
                    'deliv_id'       => $deliv['deliv_id'],
                    'payment_id'     => $paymentId,
                    'rank'           => 99,
                ));
            }
        }
    }

    /**
     * 無効化時。
     * Codex 推奨: 物理 DELETE より del_flg=1 + payment_options 削除。
     */
    public static function disable($arrPlugin, $objPluginInstaller = null)
    {
        $objQuery = SC_Query_Ex::getSingletonInstance();
        $modulePath = 'UnipleJpyc/payment.php';
        $row = $objQuery->getRow('payment_id', 'dtb_payment', 'module_path = ?', array($modulePath));
        if ($row) {
            $objQuery->update('dtb_payment', array(
                'del_flg'     => 1,
                'update_date' => 'CURRENT_TIMESTAMP',
            ), 'payment_id = ?', array($row['payment_id']));
            $objQuery->delete('dtb_payment_options', 'payment_id = ?', array($row['payment_id']));
        }
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

    private static function ensureLogDirectory()
    {
        if (!defined('DATA_REALDIR')) {
            return;
        }

        $logDir = DATA_REALDIR . 'logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
    }
}
