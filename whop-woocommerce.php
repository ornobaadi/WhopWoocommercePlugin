<?php
/**
 * Plugin Name: Whop Payment Gateway for WooCommerce
 * Description: Accept payments securely using Whop in WooCommerce.
 * Version: 1.0.4
 * Author: Antigravity
 * Text Domain: whop-woocommerce
 * Requires PHP: 8.1
 * Requires at least: 6.0
 * WC requires at least: 7.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Define Constants
define('WC_WHOP_VERSION', '1.0.4');
define('WC_WHOP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_WHOP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_WHOP_BASENAME', plugin_basename(__FILE__));

/**
 * Initialize the Whop WooCommerce Plugin.
 */
function whop_woocommerce_init() {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'whop_woocommerce_missing_wc_notice');
        return;
    }

    // Load Logger first
    require_once WC_WHOP_PLUGIN_DIR . 'includes/class-whop-logger.php';

    // Load API Client
    require_once WC_WHOP_PLUGIN_DIR . 'includes/class-whop-api.php';

    // Load Gateway Class
    require_once WC_WHOP_PLUGIN_DIR . 'includes/class-wc-whop-gateway.php';

    // Load Webhooks Handler
    require_once WC_WHOP_PLUGIN_DIR . 'includes/class-whop-webhooks.php';

    // Load Order Meta Display
    require_once WC_WHOP_PLUGIN_DIR . 'includes/class-whop-order-meta.php';

    // Load Subscriptions Integration
    require_once WC_WHOP_PLUGIN_DIR . 'includes/class-whop-subscriptions.php';

    // Load Product Custom Fields
    require_once WC_WHOP_PLUGIN_DIR . 'includes/class-whop-product-fields.php';

    // Load Plan Explorer Tool
    if (is_admin()) {
        require_once WC_WHOP_PLUGIN_DIR . 'includes/class-whop-plan-explorer.php';
    }

    // Register Gateway with WooCommerce
    add_filter('woocommerce_payment_gateways', 'whop_woocommerce_add_gateway');

    // Register Blocks Support
    add_action('woocommerce_blocks_payment_method_type_registration', 'whop_woocommerce_blocks_support');
}
add_action('plugins_loaded', 'whop_woocommerce_init');

/**
 * Register blocks support for Whop.
 */
function whop_woocommerce_blocks_support($payment_method_registry) {
    require_once WC_WHOP_PLUGIN_DIR . 'includes/class-wc-whop-blocks-support.php';
    $payment_method_registry->register(new WC_Whop_Blocks_Support());
}

/**
 * Register Whop payment gateway.
 *
 * @param array $gateways Existing gateways.
 * @return array Updated gateways.
 */
function whop_woocommerce_add_gateway($gateways) {
    $gateways[] = 'WC_Gateway_Whop';
    return $gateways;
}

/**
 * Admin notice for missing WooCommerce.
 */
function whop_woocommerce_missing_wc_notice() {
    ?>
    <div class="error">
        <p><?php esc_html_e('Whop Payment Gateway requires WooCommerce to be installed and active.', 'whop-woocommerce'); ?></p>
    </div>
    <?php
}

// Register Activation and Deactivation hooks
register_activation_hook(__FILE__, 'whop_woocommerce_activate');
register_deactivation_hook(__FILE__, 'whop_woocommerce_deactivate');

function whop_woocommerce_activate() {
    // Check for WooCommerce dependency during activation
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(WC_WHOP_BASENAME);
        wp_die(
            esc_html__('Whop Payment Gateway requires WooCommerce to be installed and active before it can be activated.', 'whop-woocommerce'),
            esc_html__('Plugin Activation Error', 'whop-woocommerce'),
            array('back_link' => true)
        );
    }

    // Set up webhook options / DB if needed
    if (!get_option('whop_webhook_events')) {
        add_option('whop_webhook_events', array());
    }
}

function whop_woocommerce_deactivate() {
    // Flush rewrite rules or clear scheduled events if any
}
