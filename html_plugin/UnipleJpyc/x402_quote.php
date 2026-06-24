<?php
/*
 * uniple checkout for EC-CUBE 2
 * Copyright (C) 2026 uniple inc.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2,
 * as published by the Free Software Foundation.
 */

require_once realpath(dirname(__FILE__) . '/../../require.php');
require_once realpath(dirname(__FILE__) . '/../../../data/downloads/plugin/UnipleJpyc/lib/UnipleJpyc_X402Quote.php');
require_once realpath(dirname(__FILE__) . '/../../../data/downloads/plugin/UnipleJpyc/lib/UnipleJpyc_Client.php');

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok' => false, 'error' => 'method_not_allowed'));
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'invalid_json'));
    exit;
}

$objQuery = SC_Query_Ex::getSingletonInstance();
$quoteService = new UnipleJpyc_X402Quote($objQuery);

try {
    $quote = $quoteService->createQuote($payload);
    echo json_encode(array('ok' => true, 'quote' => $quote));
} catch (InvalidArgumentException $e) {
    UnipleJpyc_Client::printLog('[uniple-x402-quote] rejected error=' . $e->getMessage());
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => $e->getMessage()));
} catch (Exception $e) {
    UnipleJpyc_Client::printLog('[uniple-x402-quote] failed error=' . $e->getMessage());
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => 'quote_failed'));
}
