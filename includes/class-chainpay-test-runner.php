<?php
/**
 * ChainPay 集成自检 Runner
 *
 * 给「Run integration test」按钮兜底的 admin-ajax 路由. 完整流程:
 *   1. ajax: chainpay_test_create   → 用商户 cp_test_xxx 创建一个 sandbox 订单
 *   2. ajax: chainpay_test_simulate → 调 ChainPay simulate-paid 触发回调
 *   3. ajax: chainpay_test_check    → 轮询 last-webhook transient 检测自家 webhook 入口是否收到
 *
 * 安全要点:
 *   - capability:  manage_woocommerce (与网关设置页同等权限, 否则越权)
 *   - nonce:       wp_create_nonce('chainpay_run_test')
 *   - 仅允许 cp_test_xxx, 拒绝 cp_live_xxx (避免被滥用产生真实可计费订单)
 *   - 自检订单 merchant_order_no 用 CP-SELFTEST-<random> 前缀, 不与 WC 订单冲突
 *
 * @package ChainPay
 */

if (!defined('ABSPATH')) {
    exit;
}

class ChainPay_Test_Runner
{
    const NONCE_ACTION = 'chainpay_run_test';
    const SELF_TEST_PREFIX = 'CP-SELFTEST-';

    public function __construct()
    {
        add_action('wp_ajax_chainpay_test_create',   [$this, 'ajax_create']);
        add_action('wp_ajax_chainpay_test_simulate', [$this, 'ajax_simulate']);
        add_action('wp_ajax_chainpay_test_check',    [$this, 'ajax_check_webhook']);
    }

    // ─── helpers ─────────────────────────────────────────────────────

    /** 401/403 守卫 */
    private function guard()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error([
                'code' => 'forbidden',
                'message' => __('Insufficient permissions.', 'chainpay-for-woocommerce'),
            ], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
    }

    /** 获取已配置的 ChainPay 网关实例 (含商户 key) */
    private function get_gateway()
    {
        $gateways = WC()->payment_gateways()->payment_gateways();
        return isset($gateways['chainpay']) ? $gateways['chainpay'] : null;
    }

    /**
     * 仅允许 cp_test_xxx 用 self-test, 防止被滥用产生计费订单.
     * 返回 WC_Gateway_ChainPay 或直接 wp_send_json_error 终止
     */
    private function require_test_gateway()
    {
        $gateway = $this->get_gateway();
        if (!$gateway || empty($gateway->api_key) || empty($gateway->api_secret)) {
            wp_send_json_error([
                'code' => 'not_configured',
                'message' => __('Configure your API Key and Secret first.', 'chainpay-for-woocommerce'),
            ], 400);
        }
        if ('test' !== $gateway->get_key_mode()) {
            wp_send_json_error([
                'code' => 'live_key_blocked',
                'message' => __('Self-test only works with a cp_test_xxx key (using a live key would create real billable orders).', 'chainpay-for-woocommerce'),
            ], 400);
        }
        return $gateway;
    }

    // ─── ajax routes ─────────────────────────────────────────────────

    /**
     * Step 1: 创建一个 sandbox 订单
     * (live_test_enabled=true 时, 这里也强制走 sandbox — 只是为了测 webhook 闭环, 不消耗 live_test 配额)
     */
    public function ajax_create()
    {
        $this->guard();
        $gateway = $this->require_test_gateway();

        $merchant_order_no = self::SELF_TEST_PREFIX . wp_generate_password(8, false, false) . '-' . time();

        $body = [
            'chain'             => $gateway->preferred_chain ?: 'TRON',
            'token'             => $gateway->preferred_token ?: 'USDT',
            'amount'            => '0.10', // 极小金额, 仅 sandbox 用, 不上链
            'merchant_order_no' => $merchant_order_no,
            'callback_url'      => home_url('/?wc-api=chainpay_webhook'),
            // 强制 sandbox: 即便商户勾了 live_test_enabled, 自检流程也只用 sandbox
            // (sandbox 一定不上链, 不消耗任何 live_test 配额).
        ];

        $client = new ChainPay_API_Client($gateway->base_url, $gateway->api_key, $gateway->api_secret);
        $result = $client->create_order($body, $merchant_order_no);

        if (is_wp_error($result)) {
            wp_send_json_error([
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ], 502);
        }

        $order_no = isset($result['order_no']) ? sanitize_text_field($result['order_no']) : '';
        $order_mode = isset($result['order_mode']) ? sanitize_text_field($result['order_mode']) : '';

        if (empty($order_no)) {
            wp_send_json_error([
                'code' => 'no_order_no',
                'message' => __('ChainPay returned no order_no.', 'chainpay-for-woocommerce'),
            ], 502);
        }
        if ('sandbox' !== $order_mode) {
            // 服务端没把订单识别成 sandbox, 通常意味着填的是 cp_live_xxx (但前面已防御过), 这里再兜一层
            wp_send_json_error([
                'code' => 'not_sandbox',
                /* translators: %s: returned order_mode (live / live_test / etc) */
                'message' => sprintf(__('Expected sandbox order, got %s. Check your API key prefix.', 'chainpay-for-woocommerce'), $order_mode),
            ], 502);
        }

        // 把 self-test 期望的 merchant_order_no 存 transient, 供 step3 验证 webhook 命中
        set_transient('chainpay_self_test_expected', [
            'merchant_order_no' => $merchant_order_no,
            'order_no' => $order_no,
            'started_at' => time(),
        ], 5 * MINUTE_IN_SECONDS);

        wp_send_json_success([
            'order_no' => $order_no,
            'merchant_order_no' => $merchant_order_no,
            'order_mode' => $order_mode,
        ]);
    }

    /**
     * Step 2: 调 ChainPay simulate-paid 触发 order.paid webhook
     */
    public function ajax_simulate()
    {
        $this->guard();
        $gateway = $this->require_test_gateway();

        $order_no = isset($_POST['order_no']) ? sanitize_text_field(wp_unslash($_POST['order_no'])) : '';
        if (empty($order_no)) {
            wp_send_json_error([
                'code' => 'missing_order_no',
                'message' => __('Missing order_no.', 'chainpay-for-woocommerce'),
            ], 400);
        }

        $client = new ChainPay_API_Client($gateway->base_url, $gateway->api_key, $gateway->api_secret);
        $result = $client->simulate_order_paid($order_no);

        if (is_wp_error($result)) {
            wp_send_json_error([
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ], 502);
        }

        wp_send_json_success([
            'order_no' => $order_no,
            'simulated' => true,
        ]);
    }

    /**
     * Step 3: 检查 webhook 是否到达 (前端轮询)
     *
     * 实现思路: webhook handler 验签成功后会写 LAST_WEBHOOK_TRANSIENT,
     * 这里读出来对比 merchant_order_no 是否匹配本次自检.
     */
    public function ajax_check_webhook()
    {
        $this->guard();

        $expected_moc = isset($_POST['merchant_order_no']) ? sanitize_text_field(wp_unslash($_POST['merchant_order_no'])) : '';
        if (empty($expected_moc)) {
            wp_send_json_error([
                'code' => 'missing_merchant_order_no',
                'message' => __('Missing merchant_order_no.', 'chainpay-for-woocommerce'),
            ], 400);
        }

        // 必须以 self-test 前缀开头, 防止被外部 ajax 利用来探测任意订单
        if (0 !== strpos($expected_moc, self::SELF_TEST_PREFIX)) {
            wp_send_json_error([
                'code' => 'invalid_merchant_order_no',
                'message' => __('Invalid self-test merchant_order_no.', 'chainpay-for-woocommerce'),
            ], 400);
        }

        $last = get_transient(ChainPay_Webhook_Handler::LAST_WEBHOOK_TRANSIENT);
        $started = get_transient('chainpay_self_test_expected');
        $started_at = is_array($started) && isset($started['started_at']) ? (int) $started['started_at'] : 0;

        if (!is_array($last)) {
            wp_send_json_success([
                'received' => false,
                'waited_seconds' => $started_at ? max(0, time() - $started_at) : 0,
            ]);
        }

        $matches = isset($last['merchant_order_no']) && $last['merchant_order_no'] === $expected_moc;
        // last_webhook 必须晚于本次 self-test 开始时间, 否则可能是上次自检留下的旧记录
        $is_after = $started_at > 0 && isset($last['received_at']) && (int) $last['received_at'] >= $started_at;

        if ($matches && $is_after) {
            wp_send_json_success([
                'received' => true,
                'event' => isset($last['event']) ? $last['event'] : '',
                'order_mode' => isset($last['order_mode']) ? $last['order_mode'] : '',
                'received_at' => isset($last['received_at']) ? (int) $last['received_at'] : 0,
                'waited_seconds' => $started_at ? max(0, time() - $started_at) : 0,
            ]);
        }

        wp_send_json_success([
            'received' => false,
            'waited_seconds' => $started_at ? max(0, time() - $started_at) : 0,
        ]);
    }
}
