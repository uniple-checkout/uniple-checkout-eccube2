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
 * uniple checkout — admin config dispatcher for EC-CUBE 2.x
 *
 * /admin/load_plugin_config.php?plugin_id=N からこのファイルが require_once される。
 * LC_Page_Admin 系 page class を直接定義 + run する形式 (= EC-CUBE 2 系標準)。
 *
 * Codex 推奨: plugin root の config.php を load_plugin_config.php から開く標準形 +
 * LC_Page_Admin 系 page class + Smarty。tpl は plugin 配下、ただし tpl_mainpage は
 * 絶対パス指定で admin template_dir 吸収を回避。CSRF は LC_Page_Admin の transactionid
 * 機構。
 */

require_once CLASS_EX_REALDIR . 'page_extends/admin/LC_Page_Admin_Ex.php';
require_once realpath(dirname(__FILE__) . '/lib/UnipleJpyc_Client.php');

class LC_Page_Plugin_UnipleJpyc_Config extends LC_Page_Admin_Ex
{
    /** @var string plugin code */
    public $plugin_code = 'UnipleJpyc';

    public function init()
    {
        parent::init();
        $this->tpl_mainpage = realpath(dirname(__FILE__) . '/templates/admin/config.tpl');
        $this->tpl_subno = 'plugin';
        $this->tpl_mainno = 'ownersstore';
        $this->tpl_subnavi = 'ownersstore/subnavi.tpl';
        $this->tpl_subtitle = 'uniple checkout 設定';
    }

    public function process()
    {
        $this->action();
        $this->sendResponse();
    }

    public function action()
    {
        $objQuery = SC_Query_Ex::getSingletonInstance();
        $objFormParam = new SC_FormParam_Ex();
        $this->initParam($objFormParam);

        $formAction = isset($_POST['form_action']) ? $_POST['form_action'] : '';
        $currentRow = $objQuery->getRow('*', 'plg_uniple_jpyc_config', 'id = ?', array(1));

        if ($formAction === 'save') {
            // CSRF: LC_Page_Admin の transactionid 機構
            $this->doValidUnipleToken();

            $objFormParam->setParam($_POST);
            $arrErr = $objFormParam->checkError();
            $arrParams = $objFormParam->getHashArray();
            $apiBaseUrl = $arrParams['api_base_url'] !== '' ? $arrParams['api_base_url'] : UnipleJpyc_Client::DEFAULT_API_BASE_URL;
            if (!UnipleJpyc_Client::isAllowedApiBaseUrl($apiBaseUrl)) {
                $arrErr['api_base_url'] = 'API Base URL は https://uniple.io または https://dev.uniple.io のみ指定できます。';
            }

            if (count($arrErr) === 0) {
                $apiKey = (string) $arrParams['api_key'];
                if ($apiKey === '' && $currentRow) {
                    $apiKey = (string) $currentRow['api_key'];
                }
                $webhookSecret = (string) $arrParams['webhook_secret'];
                if ($webhookSecret === '' && $currentRow) {
                    $webhookSecret = (string) $currentRow['webhook_secret'];
                }
                $objQuery->update('plg_uniple_jpyc_config', array(
                    'api_key'        => $apiKey,
                    'webhook_secret' => $webhookSecret,
                    'merchant_label' => $arrParams['merchant_label'],
                    'api_base_url'   => UnipleJpyc_Client::normalizeApiBaseUrl($apiBaseUrl),
                    'mode'           => in_array($arrParams['mode'], array('live', 'test'), true) ? $arrParams['mode'] : 'live',
                    'update_date'    => 'CURRENT_TIMESTAMP',
                ), 'id = ?', array(1));

                $this->arrInfo[] = '保存しました。';
                // PRG パターンの reload は cloudflared tunnel 経由で空 response になる場合があるため
                // 保存後 DB から再読込して直接 render する
                $row = $objQuery->getRow('*', 'plg_uniple_jpyc_config', 'id = ?', array(1));
                if ($row) {
                    $this->arrForm = $this->prepareFormForDisplay($row);
                }
            } else {
                $this->arrErr = $arrErr;
                $this->arrForm = $this->prepareFormForDisplay($arrParams, is_array($currentRow) ? $currentRow : array());
            }
        } else {
            // GET 時は DB から読込
            if ($currentRow) {
                $this->arrForm = $this->prepareFormForDisplay($currentRow);
            } else {
                $this->arrForm = $this->prepareFormForDisplay(array(
                    'api_key'        => '',
                    'webhook_secret' => '',
                    'merchant_label' => '',
                    'api_base_url'   => UnipleJpyc_Client::DEFAULT_API_BASE_URL,
                    'mode'           => 'live',
                ));
            }
        }

        // Webhook 受信 URL (= admin 画面で表示、加盟店が uniple admin/merchants UI に登録するため)
        $this->webhookUrl = rtrim(HTTPS_URL, '/') . '/plugin/UnipleJpyc/webhook.php';
        $this->returnUrl  = rtrim(HTTPS_URL, '/') . '/plugin/UnipleJpyc/return.php';
        $this->cancelUrl  = rtrim(HTTPS_URL, '/') . '/plugin/UnipleJpyc/cancel.php';

        // CSRF token
        $this->setUnipleTokenTo($this->arrForm);
    }

    private function initParam(SC_FormParam_Ex &$objFormParam)
    {
        $objFormParam->addParam('Merchant API Key', 'api_key', 255, 'KVa', array('MAX_LENGTH_CHECK', 'GRAPH_CHECK'));
        $objFormParam->addParam('Webhook Signing Secret', 'webhook_secret', 255, 'KVa', array('MAX_LENGTH_CHECK', 'GRAPH_CHECK'));
        $objFormParam->addParam('加盟店表示名', 'merchant_label', 100, 'KVa', array('MAX_LENGTH_CHECK'));
        $objFormParam->addParam('API Base URL', 'api_base_url', 255, 'KVa', array('MAX_LENGTH_CHECK', 'URL_CHECK'));
        $objFormParam->addParam('動作モード', 'mode', 16, 'a', array('MAX_LENGTH_CHECK'));
    }

    private function prepareFormForDisplay($row, $secretRow = null)
    {
        $row = is_array($row) ? $row : array();
        $secretRow = is_array($secretRow) ? $secretRow : $row;

        $row['api_key_masked'] = isset($secretRow['api_key']) && (string) $secretRow['api_key'] !== ''
            ? UnipleJpyc_Client::maskToken($secretRow['api_key'])
            : '';
        $row['webhook_secret_masked'] = isset($secretRow['webhook_secret']) && (string) $secretRow['webhook_secret'] !== ''
            ? UnipleJpyc_Client::maskToken($secretRow['webhook_secret'])
            : '';
        $row['api_key'] = '';
        $row['webhook_secret'] = '';
        if (empty($row['api_base_url'])) {
            $row['api_base_url'] = UnipleJpyc_Client::DEFAULT_API_BASE_URL;
        }
        if (empty($row['mode'])) {
            $row['mode'] = 'live';
        }

        return $row;
    }

    /**
     * CSRF token helper (= LC_Page_Admin の transactionid 機構を借りる)
     */
    public function setUnipleTokenTo(&$arrForm)
    {
        $arrForm['transactionid'] = SC_Helper_Session_Ex::getToken();
    }

    public function doValidUnipleToken()
    {
        if (!SC_Helper_Session_Ex::isValidToken(true)) {
            SC_Utils_Ex::sfDispSiteError(INVALID_MOVE_ERRORR, '', true);
            exit;
        }
    }
}

$objPage = new LC_Page_Plugin_UnipleJpyc_Config();
$objPage->init();
$objPage->process();
