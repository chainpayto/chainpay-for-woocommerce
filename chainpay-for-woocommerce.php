<?php
/**
 * Plugin Name:       ChainPay for WooCommerce
 * Plugin URI:        https://chainpay.to/integrations/wordpress
 * Description:       Accept USDT / USDC crypto payments on your WooCommerce store via ChainPay. TRON, BSC, Polygon supported. No KYC required.
 * Version:           0.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Tested up to:      6.6
 * WC requires at least: 6.0
 * WC tested up to:   9.2
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

define('CHAINPAY_WC_VERSION', '0.1.0');
define('CHAINPAY_WC_PLUGIN_FILE', __FILE__);
define('CHAINPAY_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CHAINPAY_WC_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * 自检：WooCommerce 是否激活；未激活则在插件列表给一条警告且不加载网关
 */
add_action('plugins_loaded', 'chainpay_wc_init', 11);
function chainpay_wc_init()
{
    load_plugin_textdomain(
        'chainpay-for-woocommerce',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages/'
    );

    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'chainpay_wc_missing_wc_notice');
        return;
    }

    require_once CHAINPAY_WC_PLUGIN_DIR . 'includes/class-chainpay-api-client.php';
    require_once CHAINPAY_WC_PLUGIN_DIR . 'includes/class-chainpay-webhook-handler.php';
    require_once CHAINPAY_WC_PLUGIN_DIR . 'includes/class-wc-gateway-chainpay.php';

    add_filter('woocommerce_payment_gateways', 'chainpay_wc_add_gateway');

    // Webhook 挂在 WooCommerce API 端点，路径：/?wc-api=chainpay_webhook
    new ChainPay_Webhook_Handler();
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
