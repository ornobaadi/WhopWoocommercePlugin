<?php
/**
 * Whop Admin Extensions
 */

if (!defined('ABSPATH')) {
    exit;
}

class Whop_Admin {

    /**
     * Initialize admin hooks.
     */
    public static function init() {
        // Register custom search fields for WooCommerce orders and subscriptions
        add_filter('woocommerce_shop_order_search_fields', array(__CLASS__, 'add_search_fields'));

        // Display Instagram Username in the order detail list/admin views
        add_action('woocommerce_admin_order_data_after_billing_address', array(__CLASS__, 'display_instagram_username_admin'), 10, 1);
    }

    /**
     * Extend WooCommerce search keys to include Instagram Username.
     *
     * @param array $search_fields Existing search fields.
     * @return array Updated search fields.
     */
    public static function add_search_fields($search_fields) {
        $search_fields[] = '_order_instagram_username';
        return $search_fields;
    }

    /**
     * Display the Instagram Username in order details screen in wp-admin.
     *
     * @param WC_Order $order The WooCommerce Order.
     */
    public static function display_instagram_username_admin($order) {
        $instagram = $order->get_meta('_order_instagram_username');
        if (!empty($instagram)) {
            echo '<p><strong>' . esc_html__('Instagram Username:', 'whop-woocommerce') . '</strong> ' . esc_html($instagram) . '</p>';
        }
    }
}

// Hook registration
Whop_Admin::init();
