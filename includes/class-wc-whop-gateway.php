<?php
/**
 * Whop Gateway Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_Whop extends WC_Payment_Gateway {

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        $this->id                 = 'whop';
        $this->icon               = ''; // Optional: path to an icon
        $this->has_fields         = false;
        $this->method_title       = __('Whop', 'whop-woocommerce');
        $this->method_description = __('Pay securely using Whop as your payment processor.', 'whop-woocommerce');

        // Load settings form fields
        $this->init_form_fields();
        $this->init_settings();

        // Define properties from settings
        $this->enabled       = $this->get_option('enabled');
        $this->title         = $this->get_option('title', __('Whop', 'whop-woocommerce'));
        $this->description   = $this->get_option('description', __('Pay securely with Whop.', 'whop-woocommerce'));
        $this->company_id    = $this->get_option('company_id');
        $this->api_key       = $this->get_option('api_key');
        $this->webhook_secret= $this->get_option('webhook_secret');
        $this->debug_log     = $this->get_option('debug_log') === 'yes';

        // Declare gateway support features
        $this->supports = array(
            'products',
            'subscriptions',
            'subscription_renewal',
            'tokenization',
            'refunds',
        );

        // Save settings action
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        // Handle scheduled renewal payments
        add_action('woocommerce_scheduled_subscription_payment_' . $this->id, array($this, 'scheduled_subscription_payment'), 10, 2);
    }

    /**
     * Initialize gateway settings form fields.
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __('Enable/Disable', 'whop-woocommerce'),
                'type'    => 'checkbox',
                'label'   => __('Enable Whop Payment Gateway', 'whop-woocommerce'),
                'default' => 'no',
            ),
            'title' => array(
                'title'       => __('Title', 'whop-woocommerce'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'whop-woocommerce'),
                'default'     => __('Whop', 'whop-woocommerce'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'whop-woocommerce'),
                'type'        => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'whop-woocommerce'),
                'default'     => __('Pay securely with Whop.', 'whop-woocommerce'),
            ),
            'company_id' => array(
                'title'       => __('Company ID', 'whop-woocommerce'),
                'type'        => 'text',
                'description' => __('Enter your Whop Company ID (e.g. biz_xxxxxxxxxx).', 'whop-woocommerce'),
                'default'     => '',
            ),
            'api_key' => array(
                'title'       => __('API Key', 'whop-woocommerce'),
                'type'        => 'password',
                'description' => __('Enter your Whop API Key.', 'whop-woocommerce'),
                'default'     => '',
            ),
            'webhook_secret' => array(
                'title'       => __('Webhook Secret', 'whop-woocommerce'),
                'type'        => 'password',
                'description' => __('Enter your Whop Webhook Secret.', 'whop-woocommerce'),
                'default'     => '',
            ),
            'debug_log' => array(
                'title'   => __('Debug Logging', 'whop-woocommerce'),
                'type'    => 'checkbox',
                'label'   => __('Enable Debug Logging', 'whop-woocommerce'),
                'default' => 'no',
            ),
        );
    }

    /**
     * Process the payment and return the result.
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Unable to load the order. Please try again.', 'whop-woocommerce'), 'error');
            return array('result' => 'fail');
        }

        $currency = $order->get_currency();
        $amount = (float) $order->get_total();

        if ($amount <= 0) {
            wc_add_notice(__('The order total must be greater than zero.', 'whop-woocommerce'), 'error');
            return array('result' => 'fail');
        }

        // Prepare checkout payload
        $checkout_args = array(
            'mode' => 'payment',
            'plan' => array(
                'initial_price' => $amount,
                'currency'      => strtolower($currency),
                'plan_type'     => 'one_time',
            ),
            'redirect_url' => $this->get_return_url($order),
            'metadata' => array(
                'woocommerce_order_id'    => (string) $order_id,
                'woocommerce_customer_id' => (string) $order->get_customer_id(),
            ),
        );

        Whop_Logger::log("Creating checkout configuration for Order #{$order_id}.", 'info');

        // Create Checkout Configuration in Whop API
        $response = Whop_API::create_checkout_configuration($checkout_args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            Whop_Logger::log("Failed to create Whop checkout configuration for Order #{$order_id} ({$currency} {$amount}): {$error_message}", 'error');
            wc_add_notice(
                sprintf(__('Whop could not start this payment: %s', 'whop-woocommerce'), $error_message),
                'error'
            );
            return array('result' => 'fail');
        }

        // Get checkout URL
        $checkout_url = isset($response['purchase_url']) ? $response['purchase_url'] : '';
        $checkout_id  = isset($response['id']) ? $response['id'] : '';

        if (empty($checkout_url)) {
            Whop_Logger::log("No purchase_url returned in Whop API response for Order #{$order_id}.", 'error');
            wc_add_notice(
                __('Invalid response from payment processor. Please try again later.', 'whop-woocommerce'),
                'error'
            );
            return array('result' => 'fail');
        }

        // Store checkout ID on the order
        $order->update_meta_data('_whop_checkout_configuration_id', $checkout_id);
        $order->save();

        Whop_Logger::log("Successfully generated Whop checkout URL: {$checkout_url} for Order #{$order_id}.", 'info');

        // Redirect to Whop Checkout
        return array(
            'result'   => 'success',
            'redirect' => $checkout_url,
        );
    }

    /**
     * Process a scheduled subscription renewal payment.
     *
     * @param float    $amount_to_charge The renewal amount.
     * @param WC_Order $renewal_order    The renewal order.
     */
    public function scheduled_subscription_payment($amount_to_charge, $renewal_order) {
        $order_id = $renewal_order->get_id();
        Whop_Logger::log("Processing scheduled subscription renewal payment for Order #{$order_id}.", 'info');

        // Get subscription for renewal order
        if (!function_exists('wcs_get_subscriptions_for_renewal_order')) {
            Whop_Logger::log("WooCommerce Subscriptions function wcs_get_subscriptions_for_renewal_order does not exist.", 'error');
            $renewal_order->update_status('failed', __('Subscription functions missing.', 'whop-woocommerce'));
            return;
        }

        $subscriptions = wcs_get_subscriptions_for_renewal_order($renewal_order);
        if (empty($subscriptions)) {
            Whop_Logger::log("No subscriptions found for renewal order #{$order_id}.", 'error');
            $renewal_order->update_status('failed', __('Parent subscription not found.', 'whop-woocommerce'));
            return;
        }

        $subscription = reset($subscriptions);
        $member_id  = $subscription->get_meta('_whop_member_id');
        $method_id  = $subscription->get_meta('_whop_payment_method_id');

        if (empty($member_id) || empty($method_id)) {
            Whop_Logger::log("Missing Whop member_id or payment_method_id for Subscription #{$subscription->get_id()}.", 'error');
            $renewal_order->update_status('failed', __('Missing saved Whop payment method details.', 'whop-woocommerce'));
            return;
        }

        // Check if there is already a payment ID mapped to prevent duplicate payments
        if ($renewal_order->get_meta('_whop_payment_id')) {
            Whop_Logger::log("Renewal order #{$order_id} already has a Whop payment ID associated. Skipping request.", 'info');
            return;
        }

        // Construct parameters for off-session charge
        $args = array(
            'company_id'        => $this->company_id,
            'member_id'         => $member_id,
            'payment_method_id' => $method_id,
            'plan'              => array(
                'initial_price' => (float) $amount_to_charge,
                'currency'      => strtolower($renewal_order->get_currency()),
                'plan_type'     => 'one_time',
            ),
            'metadata'          => array(
                'woocommerce_order_id'        => (string) $order_id,
                'woocommerce_subscription_id' => (string) $subscription->get_id(),
            ),
        );

        Whop_Logger::log("Sending off-session payment request to Whop API for Order #{$order_id}.", 'info');
        $response = Whop_API::create_off_session_payment($args);

        if (is_wp_error($response)) {
            Whop_Logger::log("Off-session payment request failed for Order #{$order_id}: " . $response->get_error_message(), 'error');
            $renewal_order->update_status('failed', sprintf(__('Whop payment request failed: %s', 'whop-woocommerce'), $response->get_error_message()));
            return;
        }

        $payment_id = isset($response['id']) ? $response['id'] : '';
        if ($payment_id) {
            $renewal_order->update_meta_data('_whop_payment_id', $payment_id);
            $renewal_order->save();
        }

        // Set order to pending payment as the payment result will be handled asynchronously via webhooks
        $renewal_order->update_status('pending', __('Whop off-session payment request sent. Awaiting webhook confirmation.', 'whop-woocommerce'));
    }

    /**
     * Process a refund.
     *
     * @param int    $order_id Order ID.
     * @param float  $amount   Refund amount.
     * @param string $reason   Refund reason.
     * @return bool|WP_Error True if refund is successful, or WP_Error on failure.
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('invalid_order', __('Invalid order ID.', 'whop-woocommerce'));
        }

        $payment_id = $order->get_meta('_whop_payment_id');
        if (empty($payment_id)) {
            return new WP_Error('missing_payment_id', __('No Whop payment ID associated with this order.', 'whop-woocommerce'));
        }

        if (empty($amount) || $amount <= 0) {
            return new WP_Error('invalid_amount', __('Refund amount must be greater than zero.', 'whop-woocommerce'));
        }

        Whop_Logger::log("Initiating refund of {$amount} USD for Order #{$order_id} (Payment ID: {$payment_id}).", 'info');
        $response = Whop_API::refund_payment($payment_id, (float) $amount);

        if (is_wp_error($response)) {
            Whop_Logger::log("Refund failed for Order #{$order_id}: " . $response->get_error_message(), 'error');
            return $response;
        }

        $order->add_order_note(sprintf(__('Whop refund of %1$s succeeded. Reason: %2$s', 'whop-woocommerce'), wc_price($amount), $reason));
        Whop_Logger::log("Refund of {$amount} USD succeeded for Order #{$order_id}.", 'info');
        return true;
    }
}
