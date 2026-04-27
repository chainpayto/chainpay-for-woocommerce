<?php
/**
 * Plugin Name:       ChainPay for WooCommerce
 * Plugin URI:        https://chainpay.to/integrations/wordpress
 * Description:       Accept USDT / USDC crypto payments on your WooCommerce store via ChainPay. TRON, BSC, Polygon supported. No KYC required. Built-in sandbox + real-chain 0-fee testing.
 * Version:           0.2.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Tested up to:      6.9
 * WC requires at least: 6.0
 * WC tested up to:   10.7
 * Author:            ChainPay
 * Author URI:        https://chainpay.to
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       chainpay-for-woocommerce
 * Domain Path:       /languages
 *
 * @package ChainPay
 */

if (!defined('ABSPATH')) {
    exit; // No direct access
}

define('CHAINPAY_WC_VERSION', '0.2.0');
define('CHAINPAY_WC_PLUGIN_FILE', __FILE__);
define('CHAINPAY_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CHAINPAY_WC_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * 自检：WooCommerce 是否激活；未激活则在插件列表给一条警告且不加载网关
 */
add_action('plugins_loaded', 'chainpay_wc_init', 11);
function chainpay_wc_init()
{
    // 不再手动 load_plugin_textdomain():WP 4.6+ 会按 plugin slug 自动加载 .mo,
    // 手动调用反而被 Plugin Check 警告 (DiscouragedFunctions.load_plugin_textdomainFound)。

    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'chainpay_wc_missing_wc_notice');
        return;
    }

    require_once CHAINPAY_WC_PLUGIN_DIR . 'includes/class-chainpay-api-client.php';
    require_once CHAINPAY_WC_PLUGIN_DIR . 'includes/class-chainpay-webhook-handler.php';
    require_once CHAINPAY_WC_PLUGIN_DIR . 'includes/class-wc-gateway-chainpay.php';
    require_once CHAINPAY_WC_PLUGIN_DIR . 'includes/class-chainpay-test-runner.php';

    add_filter('woocommerce_payment_gateways', 'chainpay_wc_add_gateway');

    // Webhook 挂在 WooCommerce API 端点，路径：/?wc-api=chainpay_webhook
    new ChainPay_Webhook_Handler();

    // 集成自检 (admin only) — 见 includes/class-chainpay-test-runner.php
    if (is_admin()) {
        new ChainPay_Test_Runner();
    }
}

/**
 * 仅在 ChainPay 网关设置页加载 admin-test.js
 * 路径: /wp-admin/admin.php?page=wc-settings&tab=checkout&section=chainpay
 */
add_action('admin_enqueue_scripts', 'chainpay_wc_admin_assets');
function chainpay_wc_admin_assets($hook)
{
    // WC settings 页 hook 在不同 WC 版本叫法略有差异, 用 GET 兜底判断
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 仅读 admin URL 参数判断当前页, 不写入
    $section = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : '';
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $tab     = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
    if ('chainpay' !== $section || 'checkout' !== $tab) {
        return;
    }

    wp_enqueue_script(
        'chainpay-admin-test',
        CHAINPAY_WC_PLUGIN_URL . 'assets/js/admin-test.js',
        ['jquery'],
        CHAINPAY_WC_VERSION,
        true
    );
    wp_localize_script('chainpay-admin-test', 'chainpayTest', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce(ChainPay_Test_Runner::NONCE_ACTION),
        'i18n'    => [
            'Creating sandbox order on ChainPay'                                      => __('Creating sandbox order on ChainPay…', 'chainpay-for-woocommerce'),
            'Sandbox order created'                                                   => __('Sandbox order created', 'chainpay-for-woocommerce'),
            'Failed to create sandbox order'                                          => __('Failed to create sandbox order', 'chainpay-for-woocommerce'),
            'Triggering simulate-paid'                                                => __('Triggering simulate-paid…', 'chainpay-for-woocommerce'),
            'simulate-paid OK, ChainPay queued the webhook'                           => __('simulate-paid OK, ChainPay queued the webhook', 'chainpay-for-woocommerce'),
            'Failed to simulate paid'                                                 => __('Failed to simulate paid', 'chainpay-for-woocommerce'),
            'Waiting for webhook to reach your site'                                  => __('Waiting for webhook to reach your site…', 'chainpay-for-woocommerce'),
            'Webhook received and verified'                                           => __('Webhook received and verified', 'chainpay-for-woocommerce'),
            'Server error checking webhook'                                           => __('Server error checking webhook', 'chainpay-for-woocommerce'),
            'Webhook timeout — your site did NOT receive the callback within 36s. Common causes:' => __('Webhook timeout — your site did NOT receive the callback within 36s. Common causes:', 'chainpay-for-woocommerce'),
            'Site is behind a firewall / NAT and not publicly reachable'              => __('Site is behind a firewall / NAT and not publicly reachable', 'chainpay-for-woocommerce'),
            'Webhook secret mismatch (server returned 401, payload dropped)'          => __('Webhook secret mismatch (server returned 401, payload dropped)', 'chainpay-for-woocommerce'),
            'home_url() returns an internal URL (e.g. localhost) — set Site Address to a public domain' => __('home_url() returns an internal URL (e.g. localhost) — set Site Address to a public domain', 'chainpay-for-woocommerce'),
        ],
    ]);
}

function chainpay_wc_add_gateway($gateways)
{
    $gateways[] = 'WC_Gateway_ChainPay';
    return $gateways;
}

function chainpay_wc_missing_wc_notice()
{
    echo '<div class="notice notice-error"><p><strong>'
        . esc_html__('ChainPay for WooCommerce', 'chainpay-for-woocommerce')
        . '</strong>: '
        . esc_html__('WooCommerce is required for this plugin to work. Please install and activate WooCommerce first.', 'chainpay-for-woocommerce')
        . '</p></div>';
}

/**
 * Plugin settings link on Plugins page
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'chainpay_wc_action_links');
function chainpay_wc_action_links($links)
{
    $settings_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=chainpay');
    $links[]      = '<a href="' . esc_url($settings_url) . '">'
                  . esc_html__('Settings', 'chainpay-for-woocommerce')
                  . '</a>';
    return $links;
}

/**
 * HPOS (High-Performance Order Storage) 兼容声明
 * 自 WooCommerce 7.1 起支持，未来 WC 会把它设为默认存储
 */
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            CHAINPAY_WC_PLUGIN_FILE,
            true
        );
    }
});
