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
 * uniple checkout — payment module shim for EC-CUBE 2.x
 *
 * EC-CUBE 2 系の load_payment_module.php は dtb_payment.module_path を
 * MODULE_REALDIR (= /data/module/) 配下に強制する仕様 (= LC_Page_Shopping_LoadPaymentModule)。
 * そのため plugin 配下に payment 本体を置きつつ、MODULE_REALDIR/UnipleJpyc/payment.php
 * に「本体を require するだけの shim」を配置する。
 *
 * 本 file は plugin enable() / install() で MODULE_REALDIR/UnipleJpyc/payment.php
 * へ copy される (= 本体 payment.php は plugin 配下に残し、ロジック更新は plugin 内で完結)。
 */

// shim 配置先: MODULE_REALDIR/UnipleJpyc/payment.php
//   = /var/www/eccube2/data/downloads/module/UnipleJpyc/payment.php
// 本体:
//   = /var/www/eccube2/data/downloads/plugin/UnipleJpyc/module/payment.php
// EC-CUBE 2.17 系では MODULE_REALDIR は data/downloads/module/ (= 旧 data/module/ ではない)。
require_once realpath(dirname(__FILE__) . '/../../plugin/UnipleJpyc/module/payment.php');
