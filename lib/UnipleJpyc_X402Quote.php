<?php
/*
 * uniple checkout for EC-CUBE 2
 * Copyright (C) 2026 uniple inc.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2,
 * as published by the Free Software Foundation.
 */

class UnipleJpyc_X402Quote
{
    const TTL_SECONDS = 900;

    /** @var SC_Query_Ex */
    private $objQuery;

    public function __construct(SC_Query_Ex $objQuery)
    {
        $this->objQuery = $objQuery;
        self::ensureTable($objQuery);
    }

    public static function ensureTable(SC_Query_Ex $objQuery)
    {
        $objQuery->query(<<<'SQL'
CREATE TABLE IF NOT EXISTS plg_uniple_jpyc_x402_quote (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    quote_id                VARCHAR(80)  NOT NULL,
    product_sku             VARCHAR(120) NOT NULL,
    product_id              INT UNSIGNED NOT NULL,
    product_class_id        INT UNSIGNED NOT NULL,
    quantity                INT UNSIGNED NOT NULL,
    product_subtotal_jpyc   VARCHAR(32)  NOT NULL,
    shipping_fee_jpyc       VARCHAR(32)  NOT NULL,
    discount_jpyc           VARCHAR(32)  NOT NULL DEFAULT '0',
    total_jpyc              VARCHAR(32)  NOT NULL,
    shipping_json           LONGTEXT     NOT NULL,
    deliv_id                INT UNSIGNED NULL DEFAULT NULL,
    deliv_name              VARCHAR(255) NOT NULL DEFAULT '',
    created_at              DATETIME     NOT NULL,
    expires_at              DATETIME     NOT NULL,
    used_at                 DATETIME     NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_x402_quote_id (quote_id),
    INDEX ix_x402_quote_product_sku (product_sku),
    INDEX ix_x402_quote_expires_at (expires_at)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci ENGINE=InnoDB
SQL
        );
    }

    public function createQuote(array $payload)
    {
        $productSku = $this->readString($payload, array('productSku', 'product_sku', 'externalId', 'external_id'));
        if ($productSku === '') {
            $productSku = $this->productSkuFromIds($payload);
        }
        if ($productSku === '') {
            throw new InvalidArgumentException('product_sku_required');
        }

        $product = $this->findProductClass($productSku);
        if (!$product) {
            throw new InvalidArgumentException('product_not_found');
        }
        if (!$this->isProductAvailable($product)) {
            throw new InvalidArgumentException('product_not_available');
        }

        $quantity = $this->readPositiveInt($payload, array('quantity', 'qty'), 1);
        if ($quantity < 1 || $quantity > 99) {
            throw new InvalidArgumentException('invalid_quantity');
        }

        $shipping = $this->normalizeShipping($this->shippingPayload($payload));
        $deliv = $this->selectDelivery($product);
        if (!$deliv) {
            throw new RuntimeException('delivery_not_configured');
        }

        $unitPrice = $this->normalizeIntegerJpyc(
            SC_Helper_TaxRule_Ex::sfCalcIncTax(
                $product['price02'],
                (int) $product['product_id'],
                (int) $product['product_class_id'],
                (int) $shipping['pref'],
                392
            )
        );
        if ($unitPrice === null || (int) $unitPrice <= 0) {
            throw new RuntimeException('invalid_product_price');
        }

        $productSubtotal = (string) ((int) $unitPrice * $quantity);
        $shippingFee = $this->calculateShippingFee($product, $deliv, (int) $shipping['pref'], (int) $productSubtotal, $quantity);
        $discount = '0';
        $total = (string) ((int) $productSubtotal + (int) $shippingFee);
        if ((int) $total <= 0) {
            throw new RuntimeException('invalid_total');
        }

        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', time() + self::TTL_SECONDS);
        $quoteId = 'uq_' . bin2hex(function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16));
        $shippingJson = json_encode($shipping, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($shippingJson) || $shippingJson === '') {
            throw new RuntimeException('shipping_encode_failed');
        }

        $this->objQuery->insert('plg_uniple_jpyc_x402_quote', array(
            'quote_id'              => $quoteId,
            'product_sku'           => $productSku,
            'product_id'            => (int) $product['product_id'],
            'product_class_id'      => (int) $product['product_class_id'],
            'quantity'              => $quantity,
            'product_subtotal_jpyc' => $productSubtotal,
            'shipping_fee_jpyc'     => $shippingFee,
            'discount_jpyc'         => $discount,
            'total_jpyc'            => $total,
            'shipping_json'         => $shippingJson,
            'deliv_id'              => (int) $deliv['deliv_id'],
            'deliv_name'            => (string) $deliv['name'],
            'created_at'            => $now,
            'expires_at'            => $expiresAt,
        ));

        $quote = $this->findQuote($quoteId);
        if (!$quote) {
            throw new RuntimeException('quote_create_failed');
        }

        return $this->response($quote);
    }

    public function findQuote($quoteId)
    {
        self::ensureTable($this->objQuery);

        return $this->objQuery->getRow('*', 'plg_uniple_jpyc_x402_quote', 'quote_id = ?', array((string) $quoteId));
    }

    public function markUsed($quoteId)
    {
        $this->objQuery->update('plg_uniple_jpyc_x402_quote', array(
            'used_at' => date('Y-m-d H:i:s'),
        ), 'quote_id = ?', array((string) $quoteId));
    }

    public function findProductClass($productSku)
    {
        if (!preg_match('/^eccube2-product-(\d+)-class-(\d+)$/', (string) $productSku, $m)) {
            return null;
        }

        $columns = implode(', ', array(
            'p.product_id',
            'p.name',
            'p.status',
            'p.del_flg AS product_del_flg',
            'pc.product_class_id',
            'pc.product_type_id',
            'pc.product_code',
            'pc.classcategory_id1',
            'pc.classcategory_id2',
            'pc.price02',
            'pc.stock',
            'pc.stock_unlimited',
            'pc.deliv_fee',
            'pc.del_flg AS class_del_flg',
            'COALESCE(cc1.name, \'\') AS classcategory_name1',
            'COALESCE(cc2.name, \'\') AS classcategory_name2',
        ));
        $from = 'dtb_products p'
            . ' INNER JOIN dtb_products_class pc ON p.product_id = pc.product_id'
            . ' LEFT JOIN dtb_classcategory cc1 ON pc.classcategory_id1 = cc1.classcategory_id'
            . ' LEFT JOIN dtb_classcategory cc2 ON pc.classcategory_id2 = cc2.classcategory_id';

        return $this->objQuery->getRow(
            $columns,
            $from,
            'p.product_id = ? AND pc.product_class_id = ? AND p.del_flg = 0 AND pc.del_flg = 0',
            array((int) $m[1], (int) $m[2])
        );
    }

    public function response($quote)
    {
        $shipping = json_decode((string) $quote['shipping_json'], true);
        if (!is_array($shipping)) {
            $shipping = array();
        }

        return array(
            'quoteId' => (string) $quote['quote_id'],
            'productSku' => (string) $quote['product_sku'],
            'quantity' => (int) $quote['quantity'],
            'productSubtotalJpyc' => (string) $quote['product_subtotal_jpyc'],
            'shippingFeeJpyc' => (string) $quote['shipping_fee_jpyc'],
            'discountJpyc' => (string) $quote['discount_jpyc'],
            'totalJpyc' => (string) $quote['total_jpyc'],
            'expiresAt' => date(DATE_ATOM, strtotime($quote['expires_at'])),
            'shipping' => $shipping,
            'quoteSource' => 'eccube2',
        );
    }

    private function productSkuFromIds(array $payload)
    {
        $productId = $this->readPositiveInt($payload, array('product_id', 'productId'), 0);
        $productClassId = $this->readPositiveInt($payload, array('product_class_id', 'productClassId'), 0);
        if ($productId > 0 && $productClassId > 0) {
            return 'eccube2-product-' . $productId . '-class-' . $productClassId;
        }

        return '';
    }

    private function isProductAvailable($product)
    {
        $price = $this->normalizeIntegerJpyc($product['price02']);
        $stockUnlimited = (int) $product['stock_unlimited'] === 1;
        $stock = $product['stock'] === null || $product['stock'] === '' ? 0 : (int) $product['stock'];

        return (int) $product['status'] === 1
            && (int) $product['product_del_flg'] === 0
            && (int) $product['class_del_flg'] === 0
            && $price !== null
            && ($stockUnlimited || $stock > 0);
    }

    private function selectDelivery($product)
    {
        $where = 'del_flg = 0 AND status = 1';
        $params = array();
        if (isset($product['product_type_id']) && (int) $product['product_type_id'] > 0) {
            $where .= ' AND product_type_id = ?';
            $params[] = (int) $product['product_type_id'];
        }
        $this->objQuery->setOrder('rank DESC, deliv_id ASC');
        $rows = $this->objQuery->select('deliv_id, name, product_type_id', 'dtb_deliv', $where, $params);
        if (!empty($rows)) {
            $this->objQuery->setOrder('');

            return $rows[0];
        }

        $this->objQuery->setOrder('rank DESC, deliv_id ASC');
        $rows = $this->objQuery->select('deliv_id, name, product_type_id', 'dtb_deliv', 'del_flg = 0 AND status = 1');
        $this->objQuery->setOrder('');

        return !empty($rows) ? $rows[0] : null;
    }

    private function calculateShippingFee($product, $deliv, $pref, $productSubtotal, $quantity)
    {
        $shippingFee = 0;
        if (defined('OPTION_PRODUCT_DELIV_FEE') && OPTION_PRODUCT_DELIV_FEE == 1) {
            $productFee = $this->normalizeIntegerJpyc($product['deliv_fee']);
            if ($productFee === null && $product['deliv_fee'] !== null && $product['deliv_fee'] !== '') {
                throw new RuntimeException('invalid_product_delivery_fee');
            }
            $shippingFee += (int) $productFee * $quantity;
        }

        if (defined('OPTION_DELIV_FEE') && OPTION_DELIV_FEE == 1) {
            $fee = $this->normalizeIntegerJpyc(SC_Helper_Delivery_Ex::getDelivFee($pref, (int) $deliv['deliv_id']));
            if ($fee === null) {
                throw new RuntimeException('invalid_delivery_fee');
            }
            $shippingFee += (int) $fee;
        }

        if (defined('DELIV_FREE_AMOUNT') && DELIV_FREE_AMOUNT > 0 && $quantity >= DELIV_FREE_AMOUNT) {
            $shippingFee = 0;
        }

        $this->objQuery->setOrder('');
        $baseInfo = $this->objQuery->getRow('free_rule', 'dtb_baseinfo');
        if ($baseInfo && isset($baseInfo['free_rule']) && $baseInfo['free_rule'] !== null && $baseInfo['free_rule'] !== '') {
            $freeRule = $this->normalizeIntegerJpyc($baseInfo['free_rule']);
            if ($freeRule !== null && (int) $freeRule > 0 && $productSubtotal >= (int) $freeRule) {
                $shippingFee = 0;
            }
        }

        return (string) max(0, $shippingFee);
    }

    private function shippingPayload(array $payload)
    {
        foreach (array('shipping', 'shippingAddress', 'shipping_address', 'delivery', 'recipient') as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }

        return $payload;
    }

    private function normalizeShipping(array $shipping)
    {
        $name01 = $this->readString($shipping, array('name01', 'lastName', 'last_name', 'familyName', 'family_name'));
        $name02 = $this->readString($shipping, array('name02', 'firstName', 'first_name', 'givenName', 'given_name'));
        $fullName = $this->readString($shipping, array('name', 'fullName', 'full_name', 'recipientName', 'recipient_name'));
        if (($name01 === '' || $name02 === '') && $fullName !== '') {
            $parts = preg_split('/\s+/u', $fullName, 2, PREG_SPLIT_NO_EMPTY);
            if ($name01 === '') {
                $name01 = isset($parts[0]) ? $parts[0] : '';
            }
            if ($name02 === '') {
                $name02 = isset($parts[1]) ? $parts[1] : '';
            }
        }

        $city = $this->readString($shipping, array('city', 'municipality', 'ward'));
        $line1 = $this->readString($shipping, array('addr01', 'address1', 'address_1', 'addressLine1', 'address_line1', 'line1', 'streetAddress', 'street_address'));
        $addr01 = trim($city . ' ' . $line1);
        $addr02 = $this->readString($shipping, array('addr02', 'address2', 'address_2', 'addressLine2', 'address_line2', 'line2', 'building', 'apartment', 'room'));
        $tel = $this->splitTel($this->readString($shipping, array('phoneNumber', 'phone_number', 'phone', 'tel', 'telephone')));
        $zip = $this->splitZip($this->readString($shipping, array('postalCode', 'postal_code', 'postCode', 'post_code', 'zipCode', 'zip_code', 'zipcode', 'zip')));
        $pref = $this->resolvePref($shipping);

        if ($name01 === '' || $name02 === '' || $addr01 === '' || $addr02 === '' || !$pref || implode('', $tel) === '' || implode('', $zip) === '') {
            throw new InvalidArgumentException('shipping_required_field_missing');
        }

        $prefName = (string) $this->objQuery->getOne('SELECT name FROM mtb_pref WHERE id = ?', array((int) $pref));

        return array(
            'name01' => mb_substr($name01, 0, 255),
            'name02' => mb_substr($name02, 0, 255),
            'kana01' => mb_substr($this->readString($shipping, array('kana01', 'kanaLastName', 'kana_last_name')) ?: 'エックス', 0, 255),
            'kana02' => mb_substr($this->readString($shipping, array('kana02', 'kanaFirstName', 'kana_first_name')) ?: 'バイヤー', 0, 255),
            'email' => mb_substr($this->readString($shipping, array('email', 'mail')), 0, 255),
            'tel01' => $tel[0],
            'tel02' => $tel[1],
            'tel03' => $tel[2],
            'phoneNumber' => $tel[0] . $tel[1] . $tel[2],
            'zip01' => $zip[0],
            'zip02' => $zip[1],
            'postalCode' => $zip[0] . $zip[1],
            'pref' => (int) $pref,
            'prefId' => (int) $pref,
            'prefName' => $prefName,
            'addr01' => mb_substr($addr01, 0, 255),
            'addr02' => mb_substr($addr02, 0, 255),
        );
    }

    private function resolvePref(array $shipping)
    {
        $prefId = $this->readString($shipping, array('prefId', 'pref_id', 'prefectureId', 'prefecture_id'));
        if ($prefId !== '' && ctype_digit($prefId)) {
            $exists = $this->objQuery->getOne('SELECT id FROM mtb_pref WHERE id = ?', array((int) $prefId));
            if ($exists !== false && $exists !== null) {
                return (int) $prefId;
            }
        }

        $prefName = $this->readString($shipping, array('pref', 'prefName', 'pref_name', 'prefecture', 'state', 'province', 'region'));
        if ($prefName !== '') {
            $prefName = $this->normalizePrefName($prefName);
            $id = $this->objQuery->getOne('SELECT id FROM mtb_pref WHERE name = ?', array($prefName));
            if ($id !== false && $id !== null) {
                return (int) $id;
            }
        }

        return 13;
    }

    private function normalizePrefName($prefName)
    {
        $prefName = trim((string) $prefName);
        $map = array(
            'tokyo' => '東京都',
            'tokyo-to' => '東京都',
            'osaka' => '大阪府',
            'osaka-fu' => '大阪府',
            'kyoto' => '京都府',
            'kyoto-fu' => '京都府',
            'hokkaido' => '北海道',
            'kanagawa' => '神奈川県',
            'saitama' => '埼玉県',
            'chiba' => '千葉県',
            'aichi' => '愛知県',
            'fukuoka' => '福岡県',
        );
        $key = strtolower(str_replace(array(' ', '_'), '-', $prefName));

        return isset($map[$key]) ? $map[$key] : $prefName;
    }

    private function splitTel($value)
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        if ($digits === '') {
            return array('', '', '');
        }
        if (strlen($digits) === 10) {
            return array(substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 4));
        }

        return array(substr($digits, 0, 3), substr($digits, 3, 4), substr($digits, 7, 4));
    }

    private function splitZip($value)
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        if ($digits === '') {
            return array('', '');
        }
        $digits = str_pad(substr($digits, 0, 7), 7, '0', STR_PAD_RIGHT);

        return array(substr($digits, 0, 3), substr($digits, 3, 4));
    }

    private function readString(array $data, array $keys)
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                return trim((string) $data[$key]);
            }
        }

        return '';
    }

    private function readPositiveInt(array $data, array $keys, $default)
    {
        $value = $this->readString($data, $keys);
        if ($value === '') {
            return (int) $default;
        }
        if (!ctype_digit($value)) {
            throw new InvalidArgumentException('invalid_integer');
        }

        return (int) $value;
    }

    private function normalizeIntegerJpyc($value)
    {
        if ($value === null || $value === '' || $value === false || !is_scalar($value)) {
            return null;
        }
        $s = trim((string) $value);
        if (!preg_match('/^(\d+)(?:\.0{1,6})?$/', $s, $m)) {
            return null;
        }
        $integer = ltrim($m[1], '0');

        return $integer === '' ? '0' : $integer;
    }
}
