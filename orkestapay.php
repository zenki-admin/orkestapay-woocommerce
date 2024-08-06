<?php
/*
 * Plugin Name: OrkestaPay
 * Plugin URI: https://wordpress.org/plugins/orkestapay/
 * Description: Orchestrate multiple payment gateways for a frictionless, reliable, and secure checkout experience.
 * Author: Zenkipay
 * Author URI: https://zenkipay.io
 * Version: 0.4.0
 * Requires at least: 5.8
 * Tested up to: 6.4.1
 * WC requires at least: 6.8
 * WC tested up to: 7.1.1
 * Text Domain: orkestapay
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

if (!defined('ABSPATH')) {
    exit();
}

define('ORKESTAPAY_WC_PLUGIN_FILE', __FILE__);
define('ORKESTAPAY_API_URL', 'https://api.orkestapay.com');
define('ORKESTAPAY_API_SAND_URL', 'https://api.sand.orkestapay.com');

// Languages traslation
load_plugin_textdomain('orkestapay', false, dirname(plugin_basename(__FILE__)) . '/languages/');

/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
add_action('plugins_loaded', 'orkestapay_init_gateway_class', 0);
add_action('woocommerce_order_refunded', 'orkestapay_woocommerce_order_refunded', 10, 2);

function orkestapay_init_gateway_class()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    include_once 'includes/class-orkestapay-logger.php';
    include_once 'includes/class-orkestapay-api.php';
    include_once 'includes/class-orkestapay-helper.php';
    include_once 'includes/class-orkestapay.php';

    add_filter('woocommerce_payment_gateways', 'orkestapay_add_gateway_class');

    function orkestapay_plugin_action_links($links)
    {
        $settings_url = esc_url(get_admin_url(null, 'admin.php?page=wc-settings&tab=checkout&section=orkestapay'));
        array_unshift($links, "<a title='Orkesta Settings Page' href='$settings_url'>" . __('Settings', 'orkestapay') . '</a>');

        return $links;
    }

    add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'orkestapay_plugin_action_links');

    /**
     * Add the Gateway to WooCommerce
     *
     * @return Array Gateway list with our gateway added
     */
    function orkestapay_add_gateway_class($gateways)
    {
        $gateways[] = 'OrkestaPay_Gateway';
        return $gateways;
    }
}

/**
 * Capture a dispute when a refund was made
 *
 * @param type $order_id
 * @param type $refund_id
 *
 */
function orkestapay_woocommerce_order_refunded($order_id, $refund_id)
{
    $order = wc_get_order($order_id);
    $refund = wc_get_order($refund_id);

    OrkestaPay_Logger::log('#orkestapay_woocommerce_order_refunded', ['order_id' => $order_id, 'refund_id' => $refund_id, 'payment_method' => $order->get_payment_method()]);

    if ($order->get_payment_method() !== 'orkestapay') {
        return;
    }

    $orkestaOrderId = get_post_meta($order_id, '_orkestapay_order_id', true);
    $orkestaPaymentId = get_post_meta($order_id, '_orkestapay_payment_id', true);
    OrkestaPay_Logger::log('#orkestapay_woocommerce_order_refunded', ['orkesta_order_id' => $orkestaOrderId, 'orkesta_payment_id' => $orkestaPaymentId]);

    if (OrkestaPay_Helper::is_null_or_empty_string($orkestaOrderId) || OrkestaPay_Helper::is_null_or_empty_string($orkestaPaymentId)) {
        return;
    }

    $refundData = ['description' => $refund->get_reason(), 'amount' => floatval($refund->get_amount())];

    try {
        $orkestapay = new OrkestaPay_Gateway();
        $apiHost = $orkestapay->getApiHost();

        OrkestaPay_API::request($refundData, "$apiHost/v1/payments/{$orkestaPaymentId}/refund", 'POST');

        $order->add_order_note('Refund was requested.');
    } catch (Exception $e) {
        OrkestaPay_Logger::error('#orkestapay_woocommerce_order_refunded', ['error' => $e->getMessage()]);
        $order->add_order_note('There was an error creating the refund: ' . $e->getMessage());
    }

    return;
}
