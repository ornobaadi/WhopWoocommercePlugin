<?php
/**
 * Whop Subscriptions Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class Whop_Subscriptions {

    /**
     * Initialize subscriptions integration hooks.
     */
    public static function init() {
        // Copy metadata from order to subscription
        add_action('woocommerce_checkout_subscription_created', array(__CLASS__, 'copy_subscription_metadata'), 10, 2);

        // Add admin meta boxes for subscriptions
        add_action('add_meta_boxes', array(__CLASS__, 'add_meta_boxes'));

        // Handle subscription status changes (such as cancellation)
        add_action('woocommerce_subscription_status_updated', array(__CLASS__, 'handle_subscription_status_changed'), 10, 3);
    }

    /**
     * Synchronize cancellations to Whop when status transitions to cancelled.
     *
     * @param WC_Subscription $subscription Subscription object.
     * @param string          $new_status    New status value.
     * @param string          $old_status    Old status value.
     */
    public static function handle_subscription_status_changed($subscription, $new_status, $old_status) {
        if ('cancelled' !== $new_status || 'cancelled' === $old_status) {
            return;
        }

        // Only handle if Whop is the payment gateway
        if ('whop' !== $subscription->get_payment_method()) {
            return;
        }

        $member_id = $subscription->get_meta('_whop_member_id');
        if (empty($member_id)) {
            Whop_Logger::log("Cancelled Subscription #{$subscription->get_id()} but no Whop member ID was found.", 'warning');
            return;
        }

        Whop_Logger::log("Subscription #{$subscription->get_id()} was cancelled. Synchronizing to Whop for member ID: {$member_id}.", 'info');
        $response = Whop_API::cancel_membership($member_id);

        if (is_wp_error($response)) {
            Whop_Logger::log("Failed to synchronize cancellation to Whop for Subscription #{$subscription->get_id()}: " . $response->get_error_message(), 'error');
            $subscription->add_order_note(sprintf(__('Failed to cancel membership in Whop: %s', 'whop-woocommerce'), $response->get_error_message()));
        } else {
            $subscription->add_order_note(__('Successfully synchronized subscription cancellation to Whop.', 'whop-woocommerce'));
            Whop_Logger::log("Successfully synchronized cancellation to Whop for Subscription #{$subscription->get_id()}.", 'info');
        }
    }

    /**
     * Copy Whop identifiers from order to subscription.
     *
     * @param WC_Subscription $subscription The subscription object.
     * @param WC_Order        $order        The order object.
     */
    public static function copy_subscription_metadata($subscription, $order) {
        $member_id  = $order->get_meta('_whop_member_id');
        $method_id  = $order->get_meta('_whop_payment_method_id');
        $payment_id = $order->get_meta('_whop_payment_id');

        if ($member_id) {
            $subscription->update_meta_data('_whop_member_id', $member_id);
        }
        if ($method_id) {
            $subscription->update_meta_data('_whop_payment_method_id', $method_id);
        }
        if ($payment_id) {
            $subscription->update_meta_data('_whop_last_payment_id', $payment_id);
        }

        $subscription->save();
        Whop_Logger::log("Copied Whop metadata from Order #{$order->get_id()} to Subscription #{$subscription->get_id()}.", 'info');
    }

    /**
     * Add metabox on Subscription edit screen.
     */
    public static function add_meta_boxes() {
        $screens = array('shop_subscription', 'woocommerce_page_wc-subscriptions');
        foreach ($screens as $screen) {
            add_meta_box(
                'whop_subscription_info',
                __('Whop Subscription Information', 'whop-woocommerce'),
                array(__CLASS__, 'render_meta_box'),
                $screen,
                'side',
                'default'
            );
        }
    }

    /**
     * Render subscription metabox.
     */
    public static function render_meta_box($post) {
        // Support both custom post types and HPOS structures
        if ($post instanceof WP_Post) {
            $subscription = wcs_get_subscription($post->ID);
        } elseif (is_a($post, 'WC_Subscription')) {
            $subscription = $post;
        } else {
            global $theorder; // In HPOS, the subscription object is often in the global $theorder variable
            if (is_a($theorder, 'WC_Subscription')) {
                $subscription = $theorder;
            } else {
                return;
            }
        }

        if (!$subscription) {
            return;
        }

        $member_id  = $subscription->get_meta('_whop_member_id');
        $method_id  = $subscription->get_meta('_whop_payment_method_id');
        $payment_id = $subscription->get_meta('_whop_last_payment_id');

        if (empty($member_id) && empty($method_id) && empty($payment_id)) {
            echo '<p>' . esc_html__('No Whop subscription data associated with this record.', 'whop-woocommerce') . '</p>';
            return;
        }

        echo '<table class="whop-meta-box-table" style="width:100%; text-align:left; border-collapse:collapse;">';
        if (!empty($member_id)) {
            echo '<tr><th style="padding:4px 0;">' . esc_html__('Whop Member:', 'whop-woocommerce') . '</th>';
            echo '<td style="padding:4px 0;"><code>' . esc_html($member_id) . '</code></td></tr>';
        }
        if (!empty($method_id)) {
            echo '<tr><th style="padding:4px 0;">' . esc_html__('Payment Method:', 'whop-woocommerce') . '</th>';
            echo '<td style="padding:4px 0;"><code>' . esc_html($method_id) . '</code></td></tr>';
        }
        if (!empty($payment_id)) {
            echo '<tr><th style="padding:4px 0;">' . esc_html__('Last Payment ID:', 'whop-woocommerce') . '</th>';
            echo '<td style="padding:4px 0;"><code>' . esc_html($payment_id) . '</code></td></tr>';
        }
        echo '</table>';
    }
}

// Hook registration
Whop_Subscriptions::init();
