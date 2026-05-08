<?php
/*
 * uniple Checkout for EC-CUBE 2.x — plugin_info
 *
 * 日本円ステーブルコイン JPYC (= 資金決済法第 2 条第 5 項に基づく電子決済手段、
 * JPYC 株式会社発行 / 関東財務局長第 00099 号 資金移動業者) による決済を、
 * EC-CUBE 2 系加盟店カートで「決済方法選択肢の 1 つ」として組込む。
 *
 * 対応バージョン: 2.17.2-p2 で MVP、2.25.0 を CI 対応。
 */

class plugin_info
{
    /** プラグインコード (dtb_plugin.plugin_code) */
    public static $PLUGIN_CODE          = 'UnipleJpyc';

    /** プラグイン名称 */
    public static $PLUGIN_NAME          = 'uniple Checkout';

    /** プラグインバージョン (= 4 系と独立な version 番号) */
    public static $PLUGIN_VERSION       = '0.1.0';

    /** EC-CUBE 対応バージョン */
    public static $COMPLIANT_VERSION    = '2.17.2';

    /** 作者 */
    public static $AUTHOR               = '株式会社 uniple';

    /** 公式 URL */
    public static $AUTHOR_SITE_URL      = 'https://uniple.io/';
    public static $PLUGIN_SITE_URL      = 'https://uniple.io/docs/merchant-api';

    /** 説明 */
    public static $DESCRIPTION          = '日本円ステーブルコイン JPYC (電子決済手段) による決済プラグイン。Hosted Checkout 経路で wallet 接続 (HashPort / MetaMask / WalletConnect) → 完走 → webhook で注文 status を更新します。';

    /** タグ (任意) */
    public static $TAG                  = 'JPYC,電子決済手段,ステーブルコイン,日本円,決済,Polygon';

    /** クラス名 (= dtb_plugin.class_name) */
    public static $CLASS_NAME           = 'UnipleJpyc';

    /** ローカル設定可能フラグ */
    public static $LOCAL_VERSION        = '0.1.0';

    /** ライセンス */
    public static $LICENSE              = 'GPL';
}
