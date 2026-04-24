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

        $this->title           = $this->get_option('title');
        $this->description     = $this->get_option('description');
        $this->enabled         = $this->get_option('enabled');
        $this->base_url        = $this->get_option('base_url', 'https://api.chainpay.to');
        $this->api_key         = $this->get_option('api_key');
        $this->api_secret      = $this->get_option('api_secret');
        $this->webhook_secret  = $this->get_option('webhook_secret');
        $this->preferred_chain = $this->get_option('preferred_chain', 'TRON');
        $this->preferred_token = $this->get_option('preferred_token', 'USDT');
        $this->debug           = 'yes' === $this->get_option('debug', 'no');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_api_chainpay_return', [$this, 'handle_return']); // 可选：用户付完回跳
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
        ];
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

        // 写入 meta + 订单备注，便于后台排查
        $order->update_meta_data('_chainpay_order_no', $order_no);
        $order->update_meta_data('_chainpay_checkout_url', $checkout_url);
        $order->set_payment_method($this->id);
        $order->set_payment_method_title($this->title);
        $order->update_status(
            'pending',
            __('ChainPay order created, awaiting on-chain payment.', 'chainpay-for-woocommerce')
        );
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
        $order_no = isset($_GET['order_no']) ? sanitize_text_field(wp_unslash($_GET['order_no'])) : '';
        if (empty($order_no)) {
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }
        $orders = wc_get_orders([
            'meta_key'   => '_chainpay_order_no',
            'meta_value' => $order_no,
            'limit'      => 1,
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
