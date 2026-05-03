<?php
/*
 * uniple JPYC Checkout — admin config dispatcher for EC-CUBE 2.x
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
        $this->tpl_subtitle = 'uniple JPYC Checkout 設定';
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

        $mode = isset($_POST['mode']) ? $_POST['mode'] : '';

        if ($mode === 'save') {
            // CSRF: LC_Page_Admin の transactionid 機構
            $this->doValidToken();

            $objFormParam->setParam($_POST);
            $arrErr = $objFormParam->checkError();
            $arrParams = $objFormParam->getHashArray();

            if (count($arrErr) === 0) {
                $objQuery->update('plg_uniple_jpyc_config', array(
                    'api_key'        => $arrParams['api_key'],
                    'webhook_secret' => $arrParams['webhook_secret'],
                    'merchant_label' => $arrParams['merchant_label'],
                    'api_base_url'   => $arrParams['api_base_url'] !== '' ? $arrParams['api_base_url'] : 'https://uniple.io',
                    'mode'           => in_array($arrParams['mode'], array('live', 'test'), true) ? $arrParams['mode'] : 'live',
                    'update_date'    => 'CURRENT_TIMESTAMP',
                ), 'id = ?', array(1));

                $this->arrInfo[] = '保存しました。';
                // PRG パターン: 同 URL に redirect
                SC_Response_Ex::reload();
                exit;
            }
            $this->arrErr = $arrErr;
            $this->arrForm = $arrParams;
        } else {
            // GET 時は DB から読込
            $row = $objQuery->getRow('*', 'plg_uniple_jpyc_config', 'id = ?', array(1));
            if ($row) {
                $this->arrForm = $row;
            } else {
                $this->arrForm = array(
                    'api_key'        => '',
                    'webhook_secret' => '',
                    'merchant_label' => '',
                    'api_base_url'   => 'https://uniple.io',
                    'mode'           => 'live',
                );
            }
        }

        // Webhook 受信 URL (= admin 画面で表示、加盟店が uniple admin/merchants UI に登録するため)
        $this->webhookUrl = rtrim(HTTPS_URL, '/') . '/plugin/UnipleJpyc/webhook.php';
        $this->returnUrl  = rtrim(HTTPS_URL, '/') . '/plugin/UnipleJpyc/return.php';
        $this->cancelUrl  = rtrim(HTTPS_URL, '/') . '/plugin/UnipleJpyc/cancel.php';

        // CSRF token
        $this->setTokenTo($this->arrForm);
    }

    private function initParam(SC_FormParam_Ex &$objFormParam)
    {
        $objFormParam->addParam('Merchant API Key', 'api_key', 255, 'KVa', array('MAX_LENGTH_CHECK', 'GRAPH_CHECK'));
        $objFormParam->addParam('Webhook Signing Secret', 'webhook_secret', 255, 'KVa', array('MAX_LENGTH_CHECK', 'GRAPH_CHECK'));
        $objFormParam->addParam('加盟店表示名', 'merchant_label', 100, 'KVa', array('MAX_LENGTH_CHECK'));
        $objFormParam->addParam('API Base URL', 'api_base_url', 255, 'KVa', array('MAX_LENGTH_CHECK', 'URL_CHECK'));
        $objFormParam->addParam('動作モード', 'mode', 16, 'a', array('MAX_LENGTH_CHECK'));
    }

    /**
     * CSRF token helper (= LC_Page_Admin の transactionid 機構を借りる)
     */
    private function setTokenTo(&$arrForm)
    {
        $arrForm['transactionid'] = SC_Helper_Session_Ex::getToken();
    }

    private function doValidToken()
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
