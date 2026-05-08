<?php
/*
 * uniple Checkout — payment module shim for EC-CUBE 2.x
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
