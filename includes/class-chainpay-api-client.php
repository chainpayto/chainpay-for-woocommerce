<?php
/**
 * ChainPay HTTP API 客户端
 *
 * 职责：对 REST API 发起已签名的请求。签名算法与 ChainPay 后端
 * backend/src/utils/crypto.js::signParams 保持一致：
 *   1. body + query 合并 + 加 timestamp 字段
 *   2. 丢掉 null/空字段、sign 字段
 *   3. key 字典序升序
 *   4. k=v 用 & 连接（嵌套对象 json_encode）
 *   5. HMAC-SHA256(payload, apiSecret) → 小写 hex
 *
 * 错误处理：任意非 2xx / 通讯失败都返回 WP_Error，上层负责 UI 呈现。
 *
 * @package ChainPay
 */

if (!defined('ABSPATH')) {
    exit;
}

class ChainPay_API_Client
{
    /** @var string */
    private $base_url;
    /** @var string */
    private $api_key;
    /** @var string */
    private $api_secret;

    public function __construct($base_url, $api_key, $api_secret)
    {
        $this->base_url   = rtrim($base_url, '/');
        $this->api_key    = trim($api_key);
        $this->api_secret = trim($api_secret);
    }

    /**
     * 创建支付订单
     *
     * @param array $order_data body 字段，调用方负责字段合规
     * @param string|null $idempotency_key 可选幂等键（强烈建议传 WC 订单号）
     * @return array|WP_Error 成功时返回 data 字段，失败时返回 WP_Error
     */
    public function create_order(array $order_data, $idempotency_key = null)
    {
        $headers = [];
        if ($idempotency_key) {
            $headers['Idempotency-Key'] = substr((string) $idempotency_key, 0, 128);
        }
        return $this->request('POST', '/v1/orders', $order_data, [], $headers);
    }

    /**
     * 查询订单状态
     */
    public function get_order($order_no)
    {
        $order_no = rawurlencode((string) $order_no);
        return $this->request('GET', '/v1/orders/' . $order_no);
    }

    /**
     * 发一个测试 webhook（用于"测试连接"按钮）
     */
    public function send_test_webhook($url)
    {
        return $this->request('POST', '/v1/merchant/webhook/test', ['url' => $url]);
    }

    /**
     * 核心：已签名请求
     */
    public function request($method, $path, array $body = [], array $query = [], array $extra_headers = [])
    {
        if (empty($this->api_key) || empty($this->api_secret)) {
            return new WP_Error(
                'chainpay_not_configured',
                __('ChainPay API key / secret is not configured.', 'chainpay-for-woocommerce')
            );
        }

        $method    = strtoupper($method);
        $timestamp = (string) time();

        // 签名原文：body + query + timestamp
        $params_for_sign = array_merge($body, $query, ['timestamp' => $timestamp]);
        $signature       = self::sign_params($params_for_sign, $this->api_secret);

        $url = $this->base_url . $path;
        if (!empty($query)) {
            $url = add_query_arg($query, $url);
        }

        $args = [
            'method'  => $method,
            'timeout' => 15,
            'headers' => array_merge(
                [
                    'Content-Type'        => 'application/json',
                    'Accept'              => 'application/json',
                    'X-Api-Key'           => $this->api_key,
                    'X-Timestamp'         => $timestamp,
                    'X-Sign'              => $signature,
                    'X-Signature-Version' => 'v1',
                    'User-Agent'          => 'ChainPay-WooCommerce/' . CHAINPAY_WC_VERSION . '; WP/' . get_bloginfo('version'),
                ],
                $extra_headers
            ),
        ];

        if ($method !== 'GET' && !empty($body)) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);
        $json = json_decode($raw, true);

        if ($code < 200 || $code >= 300) {
            // "HTTP 503" 是协议状态短语,不走 i18n,避免 translators 注释噪声
            $msg = is_array($json) && !empty($json['message'])
                ? $json['message']
                : sprintf('HTTP %d', $code);
            return new WP_Error('chainpay_http_error', $msg, [
                'status' => $code,
                'body'   => $raw,
            ]);
        }

        if (!is_array($json)) {
            return new WP_Error(
                'chainpay_invalid_response',
                __('Invalid JSON response from ChainPay.', 'chainpay-for-woocommerce'),
                ['body' => $raw]
            );
        }

        // ChainPay 成功响应格式：{ code: 0, data: {...} } 或直接裸 data
        if (isset($json['code']) && (int) $json['code'] !== 0) {
            return new WP_Error(
                'chainpay_api_error',
                isset($json['message']) ? $json['message'] : __('Unknown API error', 'chainpay-for-woocommerce'),
                $json
            );
        }

        return isset($json['data']) ? $json['data'] : $json;
    }

    /**
     * 签名算法 —— 与后端 backend/src/utils/crypto.js::signParams 严格一致
     *
     * 一致性要点(修错了签名直接 401):
     *   • 只过滤 sign / null(PHP 的 null,对应 JS 的 null+undefined),空串 "" 保留
     *   • key 用 ksort 升序
     *   • 值直接强转字符串(JS 是 `${v}`),bool 会变 "true"/"false",数字原样
     *   • 嵌套对象调用方应自己 json_encode 后再传入(后端说明同约定)
     */
    public static function sign_params(array $params, $api_secret)
    {
        unset($params['sign']);

        $filtered = [];
        foreach ($params as $k => $v) {
            if ($v === null) {
                continue;
            }
            $filtered[$k] = $v;
        }
        ksort($filtered);

        $parts = [];
        foreach ($filtered as $k => $v) {
            if (is_bool($v)) {
                $v = $v ? 'true' : 'false';
            } elseif (is_array($v) || is_object($v)) {
                // 约定:调用方应自己 json_encode,这里兜底做一次避免产出 "Array"
                $v = wp_json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $parts[] = $k . '=' . $v;
        }

        return hash_hmac('sha256', implode('&', $parts), $api_secret);
    }
}
