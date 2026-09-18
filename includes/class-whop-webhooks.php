<?php
/**
 * Whop Webhooks Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class Whop_Webhooks {

    /**
     * Initialize webhook hooks.
     */
    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    /**
     * Register REST API routes.
     */
    public static function register_routes() {
        register_rest_route('whop/v1', '/webhook', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_webhook'),
            'permission_callback' => '__return_true', // Authentication is done via signature
        ));
    }

    /**
     * Handle webhook request.
     *
     * @param WP_REST_Request $request REST request object.
     * @return WP_REST_Response
     */
    public static function handle_webhook(WP_REST_Request $request) {
        $headers = $request->get_headers();
        $payload = $request->get_body();

        Whop_Logger::log('Received Webhook call.', 'info');

        // Extract required headers (case-insensitive keys)
        $webhook_id        = self::get_header($headers, 'webhook-id');
        $webhook_timestamp = self::get_header($headers, 'webhook-timestamp');
        $webhook_signature = self::get_header($headers, 'webhook-signature');

        if (empty($webhook_id) || empty($webhook_timestamp) || empty($webhook_signature)) {
            Whop_Logger::log('Missing Svix/Whop webhook headers.', 'error');
            return new WP_REST_Response(array('error' => 'Missing headers'), 400);
        }

        if (abs(time() - intval($webhook_timestamp)) > 300) {
            Whop_Logger::log('Webhook verification failed: timestamp is outside the five-minute tolerance.', 'error');
            return new WP_REST_Response(array('error' => 'Expired timestamp'), 401);
        }

        // Validate Webhook Signature
        if (!self::verify_signature($payload, $webhook_id, $webhook_timestamp, $webhook_signature)) {
            Whop_Logger::log('Invalid webhook signature verification.', 'error');
            return new WP_REST_Response(array('error' => 'Invalid signature'), 401);
        }

        // Parse payload
        $event = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE || (!isset($event['type']) && !isset($event['event_type']))) {
            Whop_Logger::log('Invalid JSON webhook payload.', 'error');
            return new WP_REST_Response(array('error' => 'Invalid payload'), 400);
        }

        // Idempotency check
        if (self::is_event_processed($webhook_id)) {
            Whop_Logger::log("Duplicate webhook received (ID: {$webhook_id}). Already processed.", 'info');
            return new WP_REST_Response(array('success' => true, 'message' => 'Event already processed'), 200);
        }

        $event_type = isset($event['type']) ? $event['type'] : $event['event_type'];
        $event_type = str_replace('.', '_', $event_type);
        Whop_Logger::log("Processing webhook event: {$event_type} (ID: {$webhook_id})", 'info');

        // Process webhook event
        $result = self::process_event($event);

        if (is_wp_error($result)) {
            Whop_Logger::log("Error processing webhook: " . $result->get_error_message(), 'error');
            return new WP_REST_Response(array('error' => $result->get_error_message()), 500);
        }

        // Mark event as processed
        self::mark_event_processed($webhook_id);

        return new WP_REST_Response(array('success' => true), 200);
    }

    /**
     * Helper to get header value in a case-insensitive manner.
     */
    private static function get_header($headers, $key) {
        $key = strtolower($key);
        foreach ($headers as $header_key => $value) {
            if (strtolower($header_key) === $key) {
                return is_array($value) ? reset($value) : $value;
            }
        }
        return '';
    }

    /**
     * Verify the webhook signature against configured secret.
     */
    private static function verify_signature($payload, $id, $timestamp, $signature_header) {
        $settings = get_option('woocommerce_whop_settings', array());
        $secret   = isset($settings['webhook_secret']) ? trim($settings['webhook_secret']) : '';

        if (empty($secret)) {
            Whop_Logger::log('Webhook verification failed: signing secret is empty.', 'error');
            return false;
        }

        // Legacy Whop/Svix secrets use a base64-encoded key.
        if (str_starts_with($secret, 'whsec_')) {
            $secret = substr($secret, 6);
            $binary_key = base64_decode($secret, true);
            if (false === $binary_key) {
                Whop_Logger::log('Webhook verification failed: secret could not be base64 decoded.', 'error');
                return false;
            }
        } elseif (str_starts_with($secret, 'ws_')) {
            // Current Whop secrets are opaque strings; use the value after the prefix.
            $binary_key = substr($secret, 3);
        } else {
            $binary_key = $secret;
        }

        // Reconstruct to-sign string
        $to_sign = $id . '.' . $timestamp . '.' . $payload;

        // Calculate expected signature
        $expected_signature = base64_encode(hash_hmac('sha256', $to_sign, $binary_key, true));

        // Parse signature header values
        $signatures = explode(' ', $signature_header);
        foreach ($signatures as $sig) {
            $parts = explode(',', $sig);
            if (count($parts) === 2 && $parts[0] === 'v1') {
                if (hash_equals($parts[1], $expected_signature)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if the webhook event was already processed.
     */
    private static function is_event_processed($event_id) {
        $processed = get_option('whop_webhook_events', array());
        return in_array($event_id, $processed, true);
    }

    /**
     * Mark event as processed (keep log capped at 500 events).
     */
    private static function mark_event_processed($event_id) {
        $processed = get_option('whop_webhook_events', array());
        $processed[] = $event_id;

        if (count($processed) > 500) {
            array_shift($processed);
        }

        update_option('whop_webhook_events', $processed);
    }

    /**
     * Process specific webhook event.
     */
    private static function process_event($event) {
        // Accept current dotted event names and legacy underscore names.
        $event_type = isset($event['type']) ? $event['type'] : $event['event_type'];
        $event_type = str_replace('.', '_', $event_type);
        $data       = isset($event['data']) ? $event['data'] : array();

        switch ($event_type) {
            case 'payment_succeeded':
                return self::handle_payment_succeeded($data);

            case 'payment_failed':
                return self::handle_payment_failed($data);

            case 'setup_intent_succeeded':
                return self::handle_setup_intent_succeeded($data);

            case 'membership_deactivated':
                return self::handle_membership_deactivated($data);

            case 'refund_created':
                Whop_Logger::log('Refund created webhook received.', 'info');
                return true;

            default:
                Whop_Logger::log("Unhandled event type: {$event_type}", 'info');
                return true;
        }
    }

    /**
     * Handle payment.succeeded.
     */
    private static function handle_payment_succeeded($data) {
        $payment_id = isset($data['id']) ? $data['id'] : '';
        $metadata   = isset($data['metadata']) ? $data['metadata'] : array();
        $order_id   = isset($metadata['woocommerce_order_id']) ? intval($metadata['woocommerce_order_id']) : 0;
        $member_id  = isset($data['member_id']) ? $data['member_id'] : (isset($data['member']['id']) ? $data['member']['id'] : '');
        $payment_method_id = isset($data['payment_method_id']) ? $data['payment_method_id'] : (isset($data['payment_method']['id']) ? $data['payment_method']['id'] : '');

        // If order_id is missing, attempt to find associated subscription by member_id
        if (!$order_id && !empty($member_id) && function_exists('wcs_get_subscriptions')) {
            $matched_subs = wcs_get_subscriptions(array(
                'meta_key'   => '_whop_member_id',
                'meta_value' => $member_id,
                'limit'      => 1,
            ));
            if (!empty($matched_subs)) {
                $sub = reset($matched_subs);
                Whop_Logger::log("payment.succeeded matched existing subscription #{$sub->get_id()} via Member ID: {$member_id}.", 'info');
                
                // If recurring payment for existing subscription, create a renewal order
                if (function_exists('wcs_create_renewal_order')) {
                    $renewal_order = wcs_create_renewal_order($sub);
                    if ($renewal_order && !is_wp_error($renewal_order)) {
                        $renewal_order->update_meta_data('_whop_payment_id', $payment_id);
                        $renewal_order->payment_complete($payment_id);
                        $renewal_order->add_order_note(sprintf(__('Whop recurring renewal payment succeeded. Payment ID: %s', 'whop-woocommerce'), $payment_id));
                        $renewal_order->save();
                        Whop_Logger::log("Generated and completed renewal order #{$renewal_order->get_id()} for subscription #{$sub->get_id()}.", 'info');
                        return true;
                    }
                }
            }
        }

        if (!$order_id) {
            Whop_Logger::log("payment.succeeded received but woocommerce_order_id metadata is missing and no matching subscription found. Payment ID: {$payment_id}", 'warning');
            return true; // Acknowledge to prevent unnecessary retries
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            Whop_Logger::log("payment.succeeded received but order #{$order_id} not found in WooCommerce.", 'error');
            return new WP_Error('order_not_found', "Order #{$order_id} not found");
        }

        // Save metadata on order
        $order->update_meta_data('_whop_payment_id', $payment_id);

        if ($member_id) {
            $order->update_meta_data('_whop_member_id', $member_id);
        }
        if ($payment_method_id) {
            $order->update_meta_data('_whop_payment_method_id', $payment_method_id);
        }

        $order->payment_complete($payment_id);
        $order->add_order_note(sprintf(__('Whop payment succeeded. Payment ID: %s', 'whop-woocommerce'), $payment_id));
        $order->save();

        // Update WooCommerce Subscription metadata if active
        if (function_exists('wcs_get_subscriptions_for_renewal_order')) {
            $subscriptions = wcs_get_subscriptions_for_renewal_order($order);
            if (empty($subscriptions)) {
                $subscriptions = wcs_get_subscriptions_for_order($order);
            }
            foreach ($subscriptions as $subscription) {
                if ($member_id) {
                    $subscription->update_meta_data('_whop_member_id', $member_id);
                }
                if ($payment_method_id) {
                    $subscription->update_meta_data('_whop_payment_method_id', $payment_method_id);
                }
                $subscription->update_meta_data('_whop_last_payment_id', $payment_id);
                $subscription->save();
                Whop_Logger::log("Updated Subscription #{$subscription->get_id()} metadata via webhook payment.succeeded.", 'info');
            }
        }

        Whop_Logger::log("Order #{$order_id} marked as Paid via payment.succeeded webhook.", 'info');
        return true;
    }

    /**
     * Handle payment.failed.
     */
    private static function handle_payment_failed($data) {
        $payment_id = isset($data['id']) ? $data['id'] : '';
        $metadata   = isset($data['metadata']) ? $data['metadata'] : array();
        $order_id   = isset($metadata['woocommerce_order_id']) ? intval($metadata['woocommerce_order_id']) : 0;

        if (!$order_id) {
            return new WP_Error('missing_order_id', 'Order ID is missing in payment metadata');
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('order_not_found', "Order #{$order_id} not found");
        }

        $order->update_status('failed', sprintf(__('Whop payment failed. Payment ID: %s', 'whop-woocommerce'), $payment_id));
        $order->save();

        if (function_exists('wcs_get_subscriptions_for_renewal_order')) {
            foreach (wcs_get_subscriptions_for_renewal_order($order) as $subscription) {
                if ($subscription->has_status('active')) {
                    $subscription->update_status('on-hold', __('Whop payment failed; awaiting retry.', 'whop-woocommerce'));
                }
            }
        }

        Whop_Logger::log("Order #{$order_id} marked as Failed via payment_failed webhook.", 'info');
        return true;
    }

    /**
     * Handle setup_intent_succeeded — save the payment method reference.
     */
    private static function handle_setup_intent_succeeded($data) {
        $payment_method_id = isset($data['payment_method_id']) ? $data['payment_method_id'] : (isset($data['payment_method']['id']) ? $data['payment_method']['id'] : '');
        $member_id         = isset($data['member_id']) ? $data['member_id'] : (isset($data['member']['id']) ? $data['member']['id'] : '');
        $metadata          = isset($data['metadata']) ? $data['metadata'] : array();
        $order_id          = isset($metadata['woocommerce_order_id']) ? intval($metadata['woocommerce_order_id']) : 0;

        Whop_Logger::log("setup_intent_succeeded received. Member: {$member_id}, Payment Method: {$payment_method_id}, Order: {$order_id}.", 'info');

        if ($order_id && $payment_method_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order->update_meta_data('_whop_payment_method_id', $payment_method_id);
                if ($member_id) {
                    $order->update_meta_data('_whop_member_id', $member_id);
                }
                $order->save();
            }
        }

        return true;
    }

    /**
     * Handle membership_deactivated — update WooCommerce subscription status.
     */
    private static function handle_membership_deactivated($data) {
        $member_id = isset($data['member_id']) ? $data['member_id'] : (isset($data['member']['id']) ? $data['member']['id'] : (isset($data['id']) ? $data['id'] : ''));

        Whop_Logger::log("membership_deactivated received for Member: {$member_id}.", 'info');

        if (empty($member_id)) {
            return true;
        }

        // Find subscription by whop member ID
        if (function_exists('wcs_get_subscriptions')) {
            $subscriptions = wcs_get_subscriptions(array(
                'meta_key'   => '_whop_member_id',
                'meta_value' => $member_id,
                'limit'      => 1,
            ));

            foreach ($subscriptions as $subscription) {
                if ($subscription->has_status('active')) {
                    $subscription->update_status('on-hold', __('Whop membership deactivated via webhook.', 'whop-woocommerce'));
                    Whop_Logger::log("Subscription #{$subscription->get_id()} set to on-hold due to membership_deactivated.", 'info');
                }
            }
        }

        return true;
    }
}

// Hook registration
Whop_Webhooks::init();
