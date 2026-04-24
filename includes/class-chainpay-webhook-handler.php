<?php
/**
 * ChainPay Webhook 处理器
 *
 * 职责：接收 ChainPay 服务端的订单状态回调，验签后更新对应 WC 订单状态。
 *
 * 安全要点：
 *   • 用原始字节流验签（不要 json_decode 再 json_encode，顺序会变）
 *   • timestamp 容错 ≤ 5 分钟（防重放）
 *   • hash_equals 恒定时间比较
 *   • 幂等：重复回调同一个订单只处理一次（通过 order->get_status() 判断）
 *
 * 路由：/?wc-api=chainpay_webhook  (WooCommerce 内置的 api 钩子)
 *
 * @package ChainPay
 */

if (!defined('ABSPATH')) {
    exit;
}

class ChainPay_Webhook_Handler
{
    const TOLERANCE_SECONDS = 300; // 5 分钟

    public function __construct()
    {
        add_action('woocommerce_api_chainpay_webhook', [$this, 'handle']);
    }

    public function handle()
    {
        $gateway = $this->get_gateway();
        if (!$gateway) {
            $this->respond(500, 'gateway_not_configured');
            return;
        }

        $webhook_secret = $gateway->webhook_secret;
        if (empty($webhook_secret)) {
            $this->log_error('webhook_secret not set');
            $this->respond(500, 'webhook_secret_not_configured');
            return;
        }

        // 1. 读原始字节
        $raw_body = file_get_contents('php://input');
        if (false === $raw_body || '' === $raw_body) {
            $this->respond(400, 'empty_body');
            return;
        }

        // 2. 读 Header（getallheaders 在某些 nginx+php-fpm 下可能不可用，兜底 $_SERVER）
        $headers  = $this->get_all_headers();
        $sig      = $this->h($headers, 'x-chainpay-signature');
        $ts       = $this->h($headers, 'x-chainpay-timestamp');
        $version  = $this->h($headers, 'x-chainpay-signature-version') ?: 'v1';
        $event    = $this->h($headers, 'x-chainpay-event');

        if (empty($sig) || empty($ts)) {
            $this->respond(400, 'missing_headers');
            return;
        }

        if ('v1' !== $version) {
            $this->respond(400, 'unsupported_version');
            return;
        }

        // 3. 时间戳容差
        if (abs(time() - (int) $ts) > self::TOLERANCE_SECONDS) {
            $this->log_error('timestamp_expired: server=' . time() . ' got=' . $ts);
            $this->respond(400, 'timestamp_expired');
            return;
        }

        // 4. HMAC 验签:签名对象 = timestamp + "." + raw_body
        $expected = hash_hmac('sha256', $ts . '.' . $raw_body, $webhook_secret);
        if (!hash_equals($expected, $sig)) {
            $this->log_error('signature_mismatch');
            $this->respond(401, 'signature_mismatch');
            return;
        }

        // 5. 解析 body 开始业务
        $payload = json_decode($raw_body, true);
        if (!is_array($payload)) {
            $this->respond(400, 'invalid_json');
            return;
        }

        $result = $this->process_event($payload, $event);
        if (is_wp_error($result)) {
            $this->log_error('process_event failed: ' . $result->get_error_message());
            // 返回 200 告诉 ChainPay 已接收,避免频繁重试——除非是我们自己出错需要重试
            $this->respond(200, $result->get_error_message());
            return;
        }

        $this->respond(200, 'ok');
    }

    /**
     * 根据事件更新 WC 订单
     * 返回 true 或 WP_Error
     */
    private function process_event(array $payload, $event_header)
    {
        $event    = !empty($payload['event']) ? $payload['event'] : $event_header;
        $order_no = !empty($payload['order_no']) ? $payload['order_no'] : '';
        $moc      = !empty($payload['merchant_order_no']) ? $payload['merchant_order_no'] : '';

        if (empty($event)) {
            return new WP_Error('no_event', 'no event');
        }

        if ('test' === $event) {
            // 测试事件不关联订单,直接成功
            return true;
        }

        $wc_order = $this->find_wc_order($order_no, $moc);
        if (!$wc_order) {
            return new WP_Error('order_not_found', 'order_not_found: ' . $moc);
        }

        switch ($event) {
            case 'order.paid':
                $this->mark_paid($wc_order, $payload);
                break;
            case 'order.expired':
                $this->mark_expired($wc_order, $payload);
                break;
            case 'order.failed':
                $this->mark_failed($wc_order, $payload);
                break;
            default:
                $wc_order->add_order_note('ChainPay: ' . sanitize_text_field($event));
                break;
        }

        return true;
    }

    /**
     * 反查:优先 merchant_order_no=WC-<order_id> → wc_get_order(id),
     * 失败时 fallback 到 _chainpay_order_no meta
     */
    private function find_wc_order($order_no, $merchant_order_no)
    {
        if (!empty($merchant_order_no) && 0 === strpos($merchant_order_no, 'WC-')) {
            $raw_id = substr($merchant_order_no, 3);
            if (ctype_digit($raw_id)) {
                $maybe = wc_get_order((int) $raw_id);
                if ($maybe) {
                    return $maybe;
                }
            }
        }

        if (!empty($order_no)) {
            $orders = wc_get_orders([
                'limit'      => 1,
                'meta_key'   => '_chainpay_order_no',
                'meta_value' => sanitize_text_field($order_no),
            ]);
            if (!empty($orders)) {
                return $orders[0];
            }
        }

        return null;
    }

    private function mark_paid(WC_Order $order, array $payload)
    {
        // 幂等:已 processing/completed 不重复处理
        if ($order->is_paid()) {
            return;
        }

        $tx_hash  = !empty($payload['tx_hash']) ? sanitize_text_field($payload['tx_hash']) : '';
        $order_no = !empty($payload['order_no']) ? sanitize_text_field($payload['order_no']) : '';
        $amount   = !empty($payload['amount']) ? sanitize_text_field($payload['amount']) : '';
        $token    = !empty($payload['token']) ? sanitize_text_field($payload['token']) : '';
        $chain    = !empty($payload['chain']) ? sanitize_text_field($payload['chain']) : '';

        $note = sprintf(
            /* translators: 1: amount, 2: token, 3: chain, 4: tx hash */
            __('ChainPay payment received: %1$s %2$s on %3$s (tx: %4$s).', 'chainpay-for-woocommerce'),
            $amount,
            $token,
            $chain,
            $tx_hash ?: 'n/a'
        );

        if ($tx_hash) {
            $order->update_meta_data('_chainpay_tx_hash', $tx_hash);
        }
        $order->set_transaction_id($tx_hash);
        $order->payment_complete($tx_hash);
        $order->add_order_note($note);
        $order->save();
    }

    private function mark_expired(WC_Order $order, array $payload)
    {
        if ($order->has_status(['processing', 'completed'])) {
            return;
        }
        $order->update_status(
            'cancelled',
            __('ChainPay order expired without payment.', 'chainpay-for-woocommerce')
        );
    }

    private function mark_failed(WC_Order $order, array $payload)
    {
        if ($order->has_status(['processing', 'completed'])) {
            return;
        }
        $order->update_status(
            'failed',
            __('ChainPay reported payment failure.', 'chainpay-for-woocommerce')
        );
    }

    // --- helpers ---

    private function get_gateway()
    {
        $gateways = WC()->payment_gateways()->payment_gateways();
        return isset($gateways['chainpay']) ? $gateways['chainpay'] : null;
    }

    private function get_all_headers()
    {
        if (function_exists('getallheaders')) {
            $h = getallheaders();
            if (is_array($h)) {
                return array_change_key_case($h, CASE_LOWER);
            }
        }
        // Fallback：从 $_SERVER 取 HTTP_* 头
        $out = [];
        foreach ($_SERVER as $k => $v) {
            if (0 === strpos($k, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($k, 5)));
                $out[$name] = $v;
            }
        }
        return $out;
    }

    private function h($headers, $name)
    {
        $name = strtolower($name);
        return isset($headers[$name]) ? trim($headers[$name]) : '';
    }

    private function respond($code, $message)
    {
        status_header($code);
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode(['code' => (200 === $code ? 0 : $code), 'message' => $message]);
        exit;
    }

    private function log_error($msg)
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->error($msg, ['source' => 'chainpay-webhook']);
        }
    }
}
