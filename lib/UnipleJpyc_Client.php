<?php
/*
 * uniple Merchant API client for EC-CUBE 2.x
 *
 * 4 系 plugin (Symfony 6.4 + Guzzle) から PHP 標準 curl + 同等仕様で移植。
 *   - sessions API POST (= Phase 2 r22 設計訂正で ?wc=1 付与削除済、 経路は uniple SSR)
 *   - HMAC-SHA256 署名検証 (= 4 系から完全流用)
 *   - 整数文字列金額正規化
 */

class UnipleJpyc_Client
{
    const ENGINE_WC = 'wc';
    const TIMEOUT_SECONDS = 5;
    const CONNECT_TIMEOUT_SECONDS = 3;

    /** @var array {api_key, webhook_secret, merchant_label, api_base_url, mode} */
    private $config;

    public function __construct(array $config)
    {
        $this->config = array_merge(array(
            'api_key'        => '',
            'webhook_secret' => '',
            'merchant_label' => '',
            'api_base_url'   => 'https://uniple.io',
            'mode'           => 'live',
        ), $config);
    }

    /**
     * Hosted Checkout session を発行。
     *
     * @param  array $params {amountJpyc, merchantOrderId, itemName, successUrl, cancelUrl, webhookUrl}
     * @return array {ok, sessionId, checkoutUrl, payId, status, expiresAt}
     * @throws Exception transport / upstream error
     */
    public function createSession(array $params)
    {
        if ($this->config['api_key'] === '') {
            throw new Exception('uniple_api_key_not_configured');
        }
        $baseUrl = rtrim($this->config['api_base_url'] !== '' ? $this->config['api_base_url'] : 'https://uniple.io', '/');
        $endpoint = $baseUrl . '/api/merchant/checkout/sessions';

        $amountInt = $this->toIntegerJpyc($params['amountJpyc']);
        $body = array(
            'amountJpyc'        => (string) $amountInt,
            'successUrl'        => $params['successUrl'],
            'cancelUrl'         => $params['cancelUrl'],
            'clientReferenceId' => (string) $params['merchantOrderId'],
            'merchantLabel'     => $this->config['merchant_label'],
            'description'       => isset($params['itemName']) ? (string) $params['itemName'] : 'EC-CUBE 2 order',
            'lineItems'         => array(array(
                'name'       => isset($params['itemName']) ? (string) $params['itemName'] : 'EC-CUBE 2 order',
                'quantity'   => 1,
                'amountJpyc' => $amountInt,
            )),
            'splitEngine' => 'v3',
            'webhookUrl'  => $params['webhookUrl'],
        );

        $jsonBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER     => array(
                'Authorization: Bearer ' . $this->config['api_key'],
                'Content-Type: application/json',
                'Accept: application/json',
            ),
        ));
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            throw new Exception('uniple_session_unreachable: ' . $err);
        }
        $payload = json_decode($raw, true);
        if ($status !== 200 || !is_array($payload) || empty($payload['ok'])) {
            throw new Exception('uniple_session_failed: status=' . $status . ' body=' . substr((string) $raw, 0, 300));
        }

        $session = isset($payload['session']) ? $payload['session']
            : (isset($payload['data']) ? $payload['data'] : $payload);
        $sessionId = isset($session['sessionId']) ? (string) $session['sessionId'] : '';
        $checkoutUrl = isset($session['checkoutUrl']) ? (string) $session['checkoutUrl'] : '';
        if ($sessionId === '' || $checkoutUrl === '') {
            throw new Exception('uniple_session_missing_url');
        }

        // Phase 2 (= r22 設計訂正): `?wc=1` 付与削除。 経路振り分けは uniple SSR で完結。

        return array(
            'ok'          => true,
            'sessionId'   => $sessionId,
            'checkoutUrl' => $checkoutUrl,
            'payId'       => isset($session['payId']) ? (string) $session['payId'] : '',
            'status'      => isset($session['status']) ? (string) $session['status'] : '',
            'expiresAt'   => isset($session['expiresAt']) ? (string) $session['expiresAt'] : '',
        );
    }

    /**
     * Hosted Checkout session を sessionId で取得 (= last-line fallback 用)。
     *
     * uniple Codex r42 (= 2026-05-13) で contract 確定。 webhook 配信失敗時の
     * return.php 着地時に live lookup → status=completed なら mapping update +
     * cart purge 実行する fallback path で使用。
     *
     * @param  string $sessionId  uniple session ID (= `ucs_...`)
     * @return array {ok:bool, item?:array, error?:string, httpStatus:int}
     * @throws Exception  network / 5xx error は throw、 caller 側で catch して pending UI fallback
     */
    public function getCheckoutSession($sessionId)
    {
        if (!is_string($sessionId) || $sessionId === '') {
            throw new Exception('sessionId empty');
        }
        if ($this->config['api_key'] === '') {
            throw new Exception('uniple_api_key_not_configured');
        }
        $baseUrl = rtrim($this->config['api_base_url'] !== '' ? $this->config['api_base_url'] : 'https://uniple.io', '/');
        $endpoint = $baseUrl . '/api/merchant/checkout/sessions/' . rawurlencode($sessionId);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER     => array(
                'Authorization: Bearer ' . $this->config['api_key'],
                'Accept: application/json',
            ),
        ));
        $raw = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new Exception('uniple_session_lookup_network_error: ' . $err);
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new Exception('uniple_session_lookup_non_json: httpStatus=' . $httpStatus);
        }
        $data['httpStatus'] = $httpStatus;

        return $data;
    }

    /**
     * Webhook X-Uniple-Signature 検証 (HMAC-SHA256, sha256=<hex>)。
     * 4 系から完全流用。
     */
    public function verifySignature($rawBody, $sigHeader, $secret = null)
    {
        if ($secret === null) {
            $secret = $this->config['webhook_secret'];
        }
        if ($sigHeader === '' || $secret === '') {
            return false;
        }
        $provided = preg_replace('/^sha256=/', '', trim((string) $sigHeader));
        $expected = hash_hmac('sha256', (string) $rawBody, (string) $secret);
        if (strlen($provided) !== strlen($expected)) {
            return false;
        }
        return hash_equals($expected, $provided);
    }

    /**
     * "50" / "50.00" / 50 / 50.0 を 50 (int) に正規化。Codex 指摘: float 禁止。
     * "50.50" 等の小数残しは reject (= JPYC 整数前提)。
     */
    public function toIntegerJpyc($value)
    {
        if ($value === null || $value === '' || $value === false) {
            throw new InvalidArgumentException('amountJpyc empty');
        }
        $s = trim((string) $value);
        if ($s === '') {
            throw new InvalidArgumentException('amountJpyc empty');
        }
        if (preg_match('/^(\d+)$/', $s, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/^(\d+)\.0+$/', $s, $m)) {
            return (int) $m[1];
        }
        throw new InvalidArgumentException('amountJpyc not integer-compatible: ' . $s);
    }
}
