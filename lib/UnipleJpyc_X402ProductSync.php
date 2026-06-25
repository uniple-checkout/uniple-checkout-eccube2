<?php
/*
 * uniple checkout for EC-CUBE 2
 * Copyright (C) 2026 uniple inc.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2,
 * as published by the Free Software Foundation.
 */

require_once realpath(dirname(__FILE__) . '/UnipleJpyc_Client.php');

class UnipleJpyc_X402ProductSync
{
    const MAX_PRODUCTS_PER_SYNC = 200;

    /** @var SC_Query_Ex */
    private $objQuery;

    /** @var UnipleJpyc_Client */
    private $client;

    public function __construct(SC_Query_Ex $objQuery, UnipleJpyc_Client $client)
    {
        $this->objQuery = $objQuery;
        $this->client = $client;
    }

    /**
     * @return array synced / active / inactive / skipped / response
     */
    public function syncAll()
    {
        $products = array();
        $activeCount = 0;
        $inactiveCount = 0;
        $skippedCount = 0;
        $sortOrder = 0;

        foreach ($this->loadProductClasses() as $row) {
            if (count($products) >= self::MAX_PRODUCTS_PER_SYNC) {
                ++$skippedCount;
                continue;
            }

            $priceJpyc = $this->normalizePriceJpyc(
                SC_Helper_TaxRule_Ex::sfCalcIncTax(
                    $row['price02'],
                    (int) $row['product_id'],
                    (int) $row['product_class_id']
                )
            );
            if ($priceJpyc === null) {
                ++$skippedCount;
                continue;
            }

            $active = $this->isActive($row);
            if ($active) {
                ++$activeCount;
            } else {
                ++$inactiveCount;
            }

            $products[] = array(
                'externalId'  => $this->externalId($row),
                'name'        => $this->productName($row),
                'priceJpyc'   => $priceJpyc,
                'active'      => $active,
                'description' => $this->description($row),
                'imageUrl'    => $this->imageUrl($row),
                'pageUrl'     => $this->pageUrl($row),
                'taxLabel'    => '税込',
                'sortOrder'   => $sortOrder++,
            );
        }

        $response = $this->client->syncProducts($products);
        UnipleJpyc_Client::printLog('[uniple-x402] product sync completed synced=' . count($products) . ' active=' . $activeCount . ' inactive=' . $inactiveCount . ' skipped=' . $skippedCount);

        return array(
            'synced'   => count($products),
            'active'   => $activeCount,
            'inactive' => $inactiveCount,
            'skipped'  => $skippedCount,
            'response' => $response,
        );
    }

    private function loadProductClasses()
    {
        $columns = implode(', ', array(
            'p.product_id',
            'p.name',
            'p.main_comment',
            'p.main_list_comment',
            'p.main_image',
            'p.status',
            'p.del_flg AS product_del_flg',
            'pc.product_class_id',
            'pc.product_code',
            'pc.price02',
            'pc.stock',
            'pc.stock_unlimited',
            'pc.del_flg AS class_del_flg',
        ));
        $from = 'dtb_products p INNER JOIN dtb_products_class pc ON p.product_id = pc.product_id';
        $where = 'p.del_flg = 0';
        $this->objQuery->setOrder('p.product_id ASC, pc.product_class_id ASC');
        $rows = $this->objQuery->select($columns, $from, $where);
        $this->objQuery->setOrder('');

        return $rows;
    }

    private function externalId($row)
    {
        return 'eccube2-product-' . (int) $row['product_id'] . '-class-' . (int) $row['product_class_id'];
    }

    private function productName($row)
    {
        $name = trim((string) $row['name']);
        $code = trim((string) $row['product_code']);
        if ($code !== '') {
            $name .= ' / ' . $code;
        }
        if ($name === '') {
            $name = 'EC-CUBE2 product';
        }

        return mb_substr($name, 0, 255);
    }

    private function description($row)
    {
        $text = (string) (isset($row['main_comment']) && $row['main_comment'] !== '' ? $row['main_comment'] : $row['main_list_comment']);
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)));

        return mb_substr($text, 0, 1000);
    }

    private function imageUrl($row)
    {
        $image = trim((string) $row['main_image']);
        if ($image === '') {
            return '';
        }
        $base = $this->siteBaseUrl();
        $path = defined('IMAGE_SAVE_URLPATH') ? IMAGE_SAVE_URLPATH : '/upload/save_image/';

        return $base . '/' . ltrim($path, '/') . rawurlencode($image);
    }

    private function pageUrl($row)
    {
        $base = $this->siteBaseUrl();

        return $base . '/products/detail.php?product_id=' . (int) $row['product_id'];
    }

    private function siteBaseUrl()
    {
        $base = defined('HTTPS_URL') ? rtrim(HTTPS_URL, '/') : '';
        $host = parse_url($base, PHP_URL_HOST);
        if ($host !== 'localhost' && $host !== '127.0.0.1') {
            return $base;
        }

        $publicUrl = getenv('UNIPLE_PUBLIC_SITE_URL');
        if (is_string($publicUrl) && trim($publicUrl) !== '') {
            return rtrim(trim($publicUrl), '/');
        }

        if (!empty($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== 'localhost' && $_SERVER['HTTP_HOST'] !== '127.0.0.1') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

            return $scheme . '://' . $_SERVER['HTTP_HOST'];
        }

        return $base;
    }

    private function isActive($row)
    {
        $price = $this->normalizePriceJpyc($row['price02']);
        $stockUnlimited = (int) $row['stock_unlimited'] === 1;
        $stock = $row['stock'] === null || $row['stock'] === '' ? 0 : (int) $row['stock'];

        return (int) $row['status'] === 1
            && (int) $row['product_del_flg'] === 0
            && (int) $row['class_del_flg'] === 0
            && $price !== null
            && ($stockUnlimited || $stock > 0);
    }

    private function normalizePriceJpyc($value)
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        $s = trim((string) $value);
        if (!preg_match('/^(\d+)(?:\.(\d{1,6}))?$/', $s, $m)) {
            return null;
        }

        $integer = ltrim($m[1], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = isset($m[2]) ? rtrim($m[2], '0') : '';
        if ($integer === '0' && $fraction === '') {
            return null;
        }

        return $fraction === '' ? $integer : $integer . '.' . $fraction;
    }
}
