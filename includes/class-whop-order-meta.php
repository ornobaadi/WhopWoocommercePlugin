<?php
/**
 * Whop Order Meta Box Display
 */

if (!defined('ABSPATH')) {
    exit;
}

class Whop_Order_Meta {

    /**
     * Init hooks.
     */
    public static function init() {
        add_action('add_meta_boxes', array(__CLASS__, 'add_meta_boxes'));
    }

    /**
     * Add Whop Meta Box to Order edit page.
     */
    public static function add_meta_boxes() {
        // Compatibility for both HPOS (High Performance Order Storage) and custom post types
        $screens = array('shop_order', 'woocommerce_page_wc-orders');
        foreach ($screens as $screen) {
            add_meta_box(
                'whop_payment_info',
                __('Whop Payment Information', 'whop-woocommerce'),
                array(__CLASS__, 'render_meta_box'),
                $screen,
                'side',
                'default'
            );
        }
    }

    /**
     * Render the meta box content.
     *
     * @param WP_Post|WP_Error $post Or WC_Order on newer HPOS WooCommerce versions.
     */
    public static function render_meta_box($post) {
        // Support both old and HPOS systems
        if ($post instanceof WP_Post) {
            $order = wc_get_order($post->ID);
        } elseif (is_a($post, 'WC_Order')) {
            $order = $post;
        } else {
            // HPOS fallback
            global $theorder;
            if (is_a($theorder, 'WC_Order')) {
                $order = $theorder;
            } else {
                return;
            }
        }

        if (!$order) {
            return;
        }

        // Get saved Whop meta data
        $payment_id    = $order->get_meta('_whop_payment_id');
        $member_id     = $order->get_meta('_whop_member_id');
        $method_id     = $order->get_meta('_whop_payment_method_id');
        $checkout_id   = $order->get_meta('_whop_checkout_configuration_id');

        if (empty($payment_id) && empty($member_id) && empty($method_id) && empty($checkout_id)) {
            echo '<p>' . esc_html__('No Whop payment data associated with this order.', 'whop-woocommerce') . '</p>';
            return;
        }

        echo '<table class="whop-meta-box-table" style="width:100%; text-align:left; border-collapse:collapse;">';
        if (!empty($payment_id)) {
            echo '<tr><th style="padding:4px 0;">' . esc_html__('Payment ID:', 'whop-woocommerce') . '</th>';
            echo '<td style="padding:4px 0;"><code>' . esc_html($payment_id) . '</code></td></tr>';
        }
        if (!empty($member_id)) {
            echo '<tr><th style="padding:4px 0;">' . esc_html__('Member ID:', 'whop-woocommerce') . '</th>';
            echo '<td style="padding:4px 0;"><code>' . esc_html($member_id) . '</code></td></tr>';
        }
        if (!empty($method_id)) {
            echo '<tr><th style="padding:4px 0;">' . esc_html__('Payment Method:', 'whop-woocommerce') . '</th>';
            echo '<td style="padding:4px 0;"><code>' . esc_html($method_id) . '</code></td></tr>';
        }
        if (!empty($checkout_id)) {
            echo '<tr><th style="padding:4px 0;">' . esc_html__('Checkout Config ID:', 'whop-woocommerce') . '</th>';
            echo '<td style="padding:4px 0;"><code>' . esc_html($checkout_id) . '</code></td></tr>';
        }
        echo '</table>';
    }
}

// Hook registration
Whop_Order_Meta::init();
