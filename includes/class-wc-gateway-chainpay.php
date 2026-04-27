<?php
/**
 * ChainPay WooCommerce 支付网关
 *
 * 职责：
 *   1. 在 WooCommerce 后台展示设置页（API Key / Secret / Webhook Secret / 链币偏好）
 *   2. 结账时用 API 客户端创建 ChainPay 订单，把用户重定向到收银台
 *   3. 记录 order_no 到 WC 订单 meta，供 Webhook 反查
 *
 * 退款：crypto 退款必须链上操作，本插件只做"标记为已退款"的提示，不自动上链。
 *
 * @package ChainPay
 */

if (!defined('ABSPATH')) {
    exit;
}

// WC_Gateway_<Name> 是 WooCommerce 官方对支付网关的强制命名约定
// (WC_Gateway_Stripe / WC_Gateway_PayPal / WC_Gateway_BACS 全部如此),
// 改成插件前缀反而破坏生态识别。Plugin Check 这个警告在此场景下是 false positive。
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class WC_Gateway_ChainPay extends WC_Payment_Gateway
{
    /** @var string */
    public $base_url;
    /** @var string */
    public $api_key;
    /** @var string */
    public $api_secret;
    /** @var string */
    public $webhook_secret;
    /** @var string */
    public $preferred_chain;
    /** @var string */
    public $preferred_token;
    /** @var bool */
    public $debug;
    /** @var bool 仅当 api_key 是 cp_test_xxx 时才生效, 标识是否启用 live_test 真链 0 费 */
    public $live_test_enabled;

    public function __construct()
    {
        $this->id                 = 'chainpay';
        $this->icon               = apply_filters(
            'chainpay_wc_gateway_icon',
            CHAINPAY_WC_PLUGIN_URL . 'assets/chainpay-logo.svg'
        );
        $this->has_fields         = false;
        $this->method_title       = __('ChainPay (USDT/USDC)', 'chainpay-for-woocommerce');
        $this->method_description = __(
            'Accept USDT / USDC on TRON, BSC and Polygon via ChainPay. Customers are redirected to a hosted checkout page.',
            'chainpay-for-woocommerce'
        );
        $this->supports           = ['products'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title             = $this->get_option('title');
        $this->description       = $this->get_option('description');
        $this->enabled           = $this->get_option('enabled');
        $this->base_url          = $this->get_option('base_url', 'https://api.chainpay.to');
        $this->api_key           = $this->get_option('api_key');
        $this->api_secret        = $this->get_option('api_secret');
        $this->webhook_secret    = $this->get_option('webhook_secret');
        $this->preferred_chain   = $this->get_option('preferred_chain', 'TRON');
        $this->preferred_token   = $this->get_option('preferred_token', 'USDT');
        $this->debug             = 'yes' === $this->get_option('debug', 'no');
        $this->live_test_enabled = 'yes' === $this->get_option('live_test_enabled', 'no');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_api_chainpay_return', [$this, 'handle_return']); // 可选：用户付完回跳
    }

    /**
     * 根据 api_key 前缀识别 key 模式.
     *
     * cp_live_xxx → live   (生产 key, 创建 live 订单, 正常手续费)
     * cp_test_xxx → test   (测试 key, 默认创建 sandbox; 加 realChain=true 且 admin 已审批 → live_test)
     * 其他 / 空    → unknown
     *
     * @return string 'live' | 'test' | 'unknown'
     */
    public function get_key_mode()
    {
        $key = (string) $this->api_key;
        if ($key === '') {
            return 'unknown';
        }
        if (0 === strpos($key, 'cp_live_')) {
            return 'live';
        }
        if (0 === strpos($key, 'cp_test_')) {
            return 'test';
        }
        return 'unknown';
    }

    /**
     * 当前实际生效的订单模式 (创建订单时会传给后端, 决定走 live / sandbox / live_test).
     *
     * 决策表:
     *   key=live                       → 'live'
     *   key=test, live_test_enabled=ON → 'live_test' (实际是否生效仍取决于 admin 是否已审批该 key)
     *   key=test, live_test_enabled=OFF → 'sandbox'
     *   key=unknown                    → 'live' (兜底, 不阻塞已配置 key 但前缀不匹配的老用户)
     *
     * @return string 'live' | 'sandbox' | 'live_test'
     */
    public function get_effective_order_mode()
    {
        $mode = $this->get_key_mode();
        if ('test' === $mode) {
            return $this->live_test_enabled ? 'live_test' : 'sandbox';
        }
        return 'live';
    }

    public function init_form_fields()
    {
        $webhook_url = home_url('/?wc-api=chainpay_webhook');
        $return_url  = home_url('/?wc-api=chainpay_return');

        $this->form_fields = [
            'enabled' => [
                'title'   => __('Enable/Disable', 'chainpay-for-woocommerce'),
                'type'    => 'checkbox',
                'label'   => __('Enable ChainPay crypto payments', 'chainpay-for-woocommerce'),
                'default' => 'no',
            ],
            'title' => [
                'title'       => __('Title', 'chainpay-for-woocommerce'),
                'type'        => 'text',
                'description' => __('This controls the title shown to customers at checkout.', 'chainpay-for-woocommerce'),
                'default'     => __('Crypto (USDT / USDC)', 'chainpay-for-woocommerce'),
                'desc_tip'    => true,
            ],
            'description' => [
                'title'       => __('Description', 'chainpay-for-woocommerce'),
                'type'        => 'textarea',
                'description' => __('Shown under the payment method at checkout.', 'chainpay-for-woocommerce'),
                'default'     => __('Pay with USDT or USDC — low fees, instant confirmation.', 'chainpay-for-woocommerce'),
                'desc_tip'    => true,
            ],

            'api_section' => [
                'title'       => __('ChainPay API credentials', 'chainpay-for-woocommerce'),
                'type'        => 'title',
                'description' => sprintf(
                    /* translators: %s: URL to the ChainPay merchant dashboard */
                    __('Generate your API key in the <a href="%s" target="_blank">ChainPay merchant dashboard</a> → API Keys.', 'chainpay-for-woocommerce'),
                    'https://chainpay.to/merchant/api-keys'
                ),
            ],
            'base_url' => [
                'title'       => __('API Base URL', 'chainpay-for-woocommerce'),
                'type'        => 'text',
                'default'     => 'https://api.chainpay.to',
                'description' => __('Usually you do NOT need to change this.', 'chainpay-for-woocommerce'),
                'desc_tip'    => true,
            ],
            'api_key' => [
                'title'   => __('API Key', 'chainpay-for-woocommerce'),
                'type'    => 'text',
                'default' => '',
            ],
            'api_secret' => [
                'title'   => __('API Secret', 'chainpay-for-woocommerce'),
                'type'    => 'password',
                'default' => '',
            ],

            'webhook_section' => [
                'title'       => __('Webhook', 'chainpay-for-woocommerce'),
                'type'        => 'title',
                'description' => sprintf(
                    /* translators: 1: webhook URL, 2: dashboard URL */
                    __('In your ChainPay dashboard → Settings, set the callback URL to:<br><code>%1$s</code><br>Then copy the <strong>Webhook Secret</strong> shown there into the field below. Manage at <a href="%2$s" target="_blank">ChainPay settings</a>.', 'chainpay-for-woocommerce'),
                    esc_url($webhook_url),
                    'https://chainpay.to/merchant/settings'
                ),
            ],
            'webhook_secret' => [
                'title'       => __('Webhook Secret', 'chainpay-for-woocommerce'),
                'type'        => 'password',
                'default'     => '',
                'description' => __('Used to verify incoming webhooks (HMAC-SHA256).', 'chainpay-for-woocommerce'),
                'desc_tip'    => true,
            ],

            'chain_section' => [
                'title'       => __('Payment preferences', 'chainpay-for-woocommerce'),
                'type'        => 'title',
            ],
            'preferred_chain' => [
                'title'   => __('Default chain', 'chainpay-for-woocommerce'),
                'type'    => 'select',
                'default' => 'TRON',
                'options' => [
                    'TRON'    => 'TRON (TRC20) — ' . __('lowest fees', 'chainpay-for-woocommerce'),
                    'BSC'     => 'BSC (BEP20)',
                    'POLYGON' => 'Polygon',
                ],
                'description' => __('The chain your ChainPay order defaults to. Customers still see the final address on the checkout page.', 'chainpay-for-woocommerce'),
                'desc_tip'    => true,
            ],
            'preferred_token' => [
                'title'   => __('Default token', 'chainpay-for-woocommerce'),
                'type'    => 'select',
                'default' => 'USDT',
                'options' => [
                    'USDT' => 'USDT',
                    'USDC' => 'USDC',
                ],
            ],

            'debug' => [
                'title'       => __('Debug log', 'chainpay-for-woocommerce'),
                'type'        => 'checkbox',
                'label'       => __('Enable debug logging (WooCommerce → Status → Logs → chainpay-*)', 'chainpay-for-woocommerce'),
                'default'     => 'no',
                'description' => __('Safe to enable temporarily during setup. Do not leave on in production.', 'chainpay-for-woocommerce'),
            ],

            // ── Sandbox / Test integration (见 docs/SANDBOX_DESIGN.md) ──
            'testing_section' => [
                'title'       => __('Sandbox & test integration', 'chainpay-for-woocommerce'),
                'type'        => 'title',
                'description' => __(
                    'Use a <code>cp_test_xxx</code> API key to test your store without spending real money. Requires saving the settings first.',
                    'chainpay-for-woocommerce'
                ),
            ],
            'mode_indicator' => [
                'type'  => 'chainpay_mode_indicator',
                'title' => __('Current mode', 'chainpay-for-woocommerce'),
            ],
            'live_test_enabled' => [
                'title'       => __('Real-chain 0-fee test', 'chainpay-for-woocommerce'),
                'type'        => 'checkbox',
                'label'       => __('Use the real blockchain at zero fees (live_test mode)', 'chainpay-for-woocommerce'),
                'default'     => 'no',
                'description' => __(
                    'Only effective when API Key starts with <code>cp_test_</code> AND the platform admin has approved your key. Caps: ≤5 USDT/order, ≤20 orders/day, ≤500 lifetime. Request approval in your <a href="https://chainpay.to/merchant/api-keys" target="_blank">ChainPay dashboard</a> after saving the key.',
                    'chainpay-for-woocommerce'
                ),
            ],
            'test_runner' => [
                'type'  => 'chainpay_test_runner',
                'title' => __('Run integration test', 'chainpay-for-woocommerce'),
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // 自定义 settings field renderer
    // WC 通过命名约定 generate_<type>_html() 找到自定义类型的渲染方法.
    // ─────────────────────────────────────────────────────────────────

    /**
     * 渲染当前模式徽章 (绿 LIVE / 紫 SANDBOX / 黄 LIVE TEST / 灰 UNKNOWN).
     */
    public function generate_chainpay_mode_indicator_html($key, $data)
    {
        $field_key = $this->get_field_key($key);
        $title     = isset($data['title']) ? $data['title'] : '';
        $key_mode  = $this->get_key_mode();
        $eff_mode  = $this->get_effective_order_mode();

        // 颜色 + 文案
        $palette = [
            'live'      => ['#10B981', '#ECFDF5', __('LIVE — real orders, real fees', 'chainpay-for-woocommerce')],
            'sandbox'   => ['#7C3AED', '#F5F3FF', __('SANDBOX — off-chain test orders, no fees', 'chainpay-for-woocommerce')],
            'live_test' => ['#B45309', '#FEF3C7', __('LIVE TEST — real chain, 0 fees, capped (admin approval required)', 'chainpay-for-woocommerce')],
        ];
        list($color, $bg, $hint) = $palette[$eff_mode];

        $key_hint = '';
        if ('unknown' === $key_mode && !empty($this->api_key)) {
            $key_hint = __('Your API key does not start with <code>cp_live_</code> or <code>cp_test_</code>; orders will default to live mode.', 'chainpay-for-woocommerce');
        } elseif ('' === $this->api_key) {
            $key_hint = __('Save your API Key first to detect the mode.', 'chainpay-for-woocommerce');
        }

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>"><?php echo esc_html($title); ?></label>
            </th>
            <td class="forminp">
                <div style="display:inline-block;padding:6px 14px;border-radius:14px;background:<?php echo esc_attr($bg); ?>;color:<?php echo esc_attr($color); ?>;font-weight:700;letter-spacing:.5px;">
                    <?php echo esc_html(strtoupper($eff_mode)); ?>
                </div>
                <p class="description" style="margin-top:8px"><?php echo esc_html($hint); ?></p>
                <?php if ($key_hint) : ?>
                    <p class="description" style="color:#B45309"><?php echo wp_kses_post($key_hint); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * 渲染「Run integration test」按钮 + 实时进度面板.
     * JS 在 assets/js/admin-test.js 中, 通过 admin-ajax.php 与后台交互.
     */
    public function generate_chainpay_test_runner_html($key, $data)
    {
        $field_key = $this->get_field_key($key);
        $title     = isset($data['title']) ? $data['title'] : '';
        $eff_mode  = $this->get_effective_order_mode();
        $key_mode  = $this->get_key_mode();
        $can_run   = ('test' === $key_mode);

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label><?php echo esc_html($title); ?></label>
            </th>
            <td class="forminp">
                <button type="button"
                    class="button button-primary"
                    id="chainpay-run-test"
                    <?php disabled(!$can_run); ?>
                    data-mode="<?php echo esc_attr($eff_mode); ?>">
                    <?php esc_html_e('Run end-to-end test', 'chainpay-for-woocommerce'); ?>
                </button>
                <?php if (!$can_run) : ?>
                    <p class="description" style="color:#B45309">
                        <?php esc_html_e('Configure a cp_test_xxx API key to enable this button. Live keys cannot be used for self-test (would create real billable orders).', 'chainpay-for-woocommerce'); ?>
                    </p>
                <?php else : ?>
                    <p class="description">
                        <?php esc_html_e('Creates a sandbox order, simulates payment, and verifies that the webhook reaches your site. ~30 seconds.', 'chainpay-for-woocommerce'); ?>
                    </p>
                <?php endif; ?>

                <div id="chainpay-test-results" style="margin-top:12px; max-width:700px;"></div>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * 结账点击"下单付款" → 创建 ChainPay 订单 → 重定向到收银台
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return ['result' => 'failure'];
        }

        // 商户订单号约定:WC-<order_id>
        // 注意用 order_id 而非 get_order_number()——后者会被顺序号插件改写,反查不稳;order_id 是永不变的主键。
        $merchant_order_no = 'WC-' . $order->get_id();

        // ChainPay 当前协议字段只有这些;return_url (付款成功后跳回商户) 后续
        // 收银台支持后可加。WC 订单通过 merchant_order_no=WC-<id> 反查关联。
        $body = [
            'chain'             => $this->preferred_chain,
            'token'             => $this->preferred_token,
            'amount'            => $this->format_amount_for_chainpay($order),
            'merchant_order_no' => $merchant_order_no,
            'callback_url'      => home_url('/?wc-api=chainpay_webhook'),
            'cancel_url'        => $order->get_cancel_order_url_raw(),
        ];

        // Sandbox/Test 集成 (见 docs/SANDBOX_DESIGN.md):
        // - cp_live_xxx → 永远 live, 不传 realChain
        // - cp_test_xxx + live_test_enabled=true → 真链 0 费 (需后端审批已通过)
        // - cp_test_xxx + live_test_enabled=false → sandbox (不上链)
        $eff_mode = $this->get_effective_order_mode();
        if ('live_test' === $eff_mode) {
            $body['realChain'] = true;
        }

        $client = new ChainPay_API_Client($this->base_url, $this->api_key, $this->api_secret);
        $result = $client->create_order($body, $merchant_order_no);

        if (is_wp_error($result)) {
            $this->log_error('create_order failed: ' . $result->get_error_message(), $result->get_error_data());
            wc_add_notice(
                sprintf(
                    /* translators: %s: error message */
                    __('Crypto payment error: %s', 'chainpay-for-woocommerce'),
                    $result->get_error_message()
                ),
                'error'
            );
            return ['result' => 'failure'];
        }

        $order_no     = isset($result['order_no']) ? sanitize_text_field($result['order_no']) : '';
        $checkout_url = !empty($result['checkout_url']) ? esc_url_raw($result['checkout_url'])
            : (!empty($result['payment_url']) ? esc_url_raw($result['payment_url']) : '');

        if (empty($checkout_url)) {
            $this->log_error('create_order returned no checkout_url', $result);
            wc_add_notice(__('Crypto payment error: missing checkout URL.', 'chainpay-for-woocommerce'), 'error');
            return ['result' => 'failure'];
        }

        // 实际生效的订单模式以后端响应为准 (后端会按 key 类型 + realChain + 审批状态最终决定).
        // 通常是 sandbox / live_test / live, 这里同步存到 WC 订单 meta 方便排查.
        $resolved_mode = isset($result['order_mode']) ? sanitize_key($result['order_mode']) : $eff_mode;

        // 写入 meta + 订单备注，便于后台排查
        $order->update_meta_data('_chainpay_order_no', $order_no);
        $order->update_meta_data('_chainpay_checkout_url', $checkout_url);
        $order->update_meta_data('_chainpay_order_mode', $resolved_mode);
        $order->set_payment_method($this->id);
        $order->set_payment_method_title($this->title);

        $note = __('ChainPay order created, awaiting on-chain payment.', 'chainpay-for-woocommerce');
        if ('sandbox' === $resolved_mode) {
            $note = __('[SANDBOX] ChainPay test order created (off-chain, no real funds will move).', 'chainpay-for-woocommerce');
        } elseif ('live_test' === $resolved_mode) {
            $note = __('[LIVE TEST] ChainPay real-chain 0-fee test order created. Caps apply.', 'chainpay-for-woocommerce');
        }
        $order->update_status('pending', $note);
        $order->save();

        return [
            'result'   => 'success',
            'redirect' => $checkout_url,
        ];
    }

    /**
     * 金额格式：WooCommerce 可能用各种法币，这里简单化——货币必须与 token 同族（USDT/USDC ≈ USD）
     * 更智能的做法是接 FX，本 MVP 要求站点以 USD 计价。
     */
    private function format_amount_for_chainpay(WC_Order $order)
    {
        // 2 位小数，字符串防浮点
        return number_format((float) $order->get_total(), 2, '.', '');
    }

    /**
     * 用户从收银台回来（return_url） → 跳到 WC 的 order-received 页
     * 这里不做关键业务逻辑(那是 webhook 的事),只做体验优化
     */
    public function handle_return()
    {
        // 跨站回跳路径:用户从 ChainPay 收银台 GET 跳回,
        // ChainPay 服务器没法签 WP nonce,这里也仅做只读 redirect,
        // 没有写库/状态变更,故安全可控。
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order_no = isset($_GET['order_no']) ? sanitize_text_field(wp_unslash($_GET['order_no'])) : '';
        if (empty($order_no)) {
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }
        // 用 meta_query 替代 meta_key/meta_value,后者被 PluginCheck 标为 slow query
        $orders = wc_get_orders([
            'limit'      => 1,
            'meta_query' => [
                [
                    'key'   => '_chainpay_order_no',
                    'value' => $order_no,
                ],
            ],
        ]);
        if (!empty($orders)) {
            wp_safe_redirect($this->get_return_url($orders[0]));
            exit;
        }
        wp_safe_redirect(wc_get_cart_url());
        exit;
    }

    public function log_error($msg, $context = null)
    {
        if (!$this->debug) {
            return;
        }
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->error(
                $msg . ($context ? ' | context: ' . wp_json_encode($context) : ''),
                ['source' => 'chainpay-gateway']
            );
        }
    }
}
