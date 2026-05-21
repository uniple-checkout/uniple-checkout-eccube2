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
