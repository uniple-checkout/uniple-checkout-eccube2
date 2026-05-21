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
 * uniple checkout for EC-CUBE 2.x — plugin_info
 *
 * 日本円ステーブルコイン JPYC (= 資金決済法第 2 条第 5 項に基づく電子決済手段、
 * JPYC 株式会社発行 / 関東財務局長第 00099 号 資金移動業者) による決済を、
 * EC-CUBE 2 系加盟店カートで「決済方法選択肢の 1 つ」として組込む。
 *
 * 対応バージョン: EC-CUBE 2.17.x / PHP 7.0+。
 */

class plugin_info
{
    /** プラグインコード (dtb_plugin.plugin_code) */
    public static $PLUGIN_CODE          = 'UnipleJpyc';

    /** プラグイン名称 */
    public static $PLUGIN_NAME          = 'uniple checkout';

    /** プラグインバージョン (= 4 系と独立な version 番号) */
    public static $PLUGIN_VERSION       = '0.1.1';

    /** EC-CUBE 対応バージョン */
    public static $COMPLIANT_VERSION    = '2.17.x';

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
    public static $LOCAL_VERSION        = '0.1.1';

    /** ライセンス */
    public static $LICENSE              = 'GPLv2 or later';
}
