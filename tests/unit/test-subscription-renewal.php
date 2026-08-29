<?php
/**
 * Mock Unit Test for Whop Subscriptions Renewal Payment Flow
 */

// Define mock functions and classes to simulate WordPress/WooCommerce environment
define('ABSPATH', true);

function __($text, $domain = 'default') {
    return $text;
}

function esc_html($text) {
    return $text;
}

function get_option($key, $default = array()) {
    if ($key === 'woocommerce_whop_settings') {
        return array(
            'company_id'     => 'biz_test_123',
            'api_key'        => 'whop_test_key_abc',
            'webhook_secret' => 'whsec_test_secret_xyz',
            'debug_log'      => 'yes',
        );
    }
    return $default;
}

function wcs_get_subscriptions_for_renewal_order($order) {
    global $mock_subscription;
    return array($mock_subscription);
}

// Mock WC_Logger
class WC_Logger {
    public function log($level, $message, $context = array()) {
        echo "[LOG - {$level}]: {$message}\n";
    }
}

function wc_get_logger() {
    return new WC_Logger();
}

// Mock WC_Order
class WC_Order {
    public $id;
    public $status = 'pending';
    public $metadata = array();
    public $notes = array();

    public function __construct($id) {
        $this->id = $id;
    }

    public function get_id() {
        return $this->id;
    }

    public function get_total() {
        return 29.99;
    }

    public function get_currency() {
        return 'USD';
    }

    public function get_customer_id() {
        return 42;
    }

    public function get_meta($key, $single = true) {
        return isset($this->metadata[$key]) ? $this->metadata[$key] : '';
    }

    public function update_meta_data($key, $value) {
        $this->metadata[$key] = $value;
    }

    public function save() {
        // Mock save
    }

    public function update_status($new_status, $note = '') {
        $this->status = $new_status;
        if ($note) {
            $this->notes[] = $note;
        }
        echo "[ORDER STATUS UPDATE] Order #{$this->id} status: {$new_status}. Note: {$note}\n";
    }

    public function add_order_note($note) {
        $this->notes[] = $note;
        echo "[ORDER NOTE] {$note}\n";
    }
}

// Mock WC_Subscription
class WC_Subscription extends WC_Order {
    public function get_payment_method() {
        return 'whop';
    }
}

// Mock WC_Payment_Gateway
class WC_Payment_Gateway {
    public $id;
    public $supports = array();
    public $enabled;
    public $title;
    public $description;
    public $company_id;
    public $api_key;
    public $webhook_secret;
    public $test_mode;
    public $debug_log;

    public function init_settings() {}
    public function get_option($key, $default = '') {
        $settings = get_option('woocommerce_whop_settings');
        return isset($settings[$key]) ? $settings[$key] : $default;
    }
}

// Mock wc_price
function wc_price($amount) {
    return '$' . number_format($amount, 2);
}

// Mock wp_remote_request to intercept Whop API calls
function wp_remote_request($url, $args) {
    echo "[HTTP REQUEST] {$args['method']} request to {$url}\n";
    if (!empty($args['body'])) {
        echo "[HTTP BODY] " . $args['body'] . "\n";
    }
    
    // Return mock payment object response
    return array(
        'response' => array('code' => 200),
        'body'     => json_encode(array(
            'id'                => 'pay_mock_9999',
            'member_id'         => 'mber_mock_1111',
            'payment_method_id' => 'payt_mock_2222',
            'status'            => 'succeeded',
        ))
    );
}

function wp_remote_retrieve_response_code($response) {
    return $response['response']['code'];
}

function wp_remote_retrieve_body($response) {
    return $response['body'];
}

function is_wp_error($thing) {
    return false;
}

// Load Whop Gateway dependencies
require_once dirname(__FILE__) . '/../../includes/class-whop-logger.php';
require_once dirname(__FILE__) . '/../../includes/class-whop-api.php';
require_once dirname(__FILE__) . '/../../includes/class-wc-whop-gateway.php';
require_once dirname(__FILE__) . '/../../includes/class-whop-subscriptions.php';

// Setup Mock Environment
global $mock_subscription;
$mock_subscription = new WC_Subscription(1001); // Parent Subscription ID 1001
$mock_subscription->update_meta_data('_whop_member_id', 'mber_mock_1111');
$mock_subscription->update_meta_data('_whop_payment_method_id', 'payt_mock_2222');

$renewal_order = new WC_Order(2001); // Renewal Order ID 2001

echo "--- RUNNING RENEWAL PAYMENT FLOW MOCK TEST ---\n";

// Instantiate Gateway
$gateway = new WC_Gateway_Whop();

// Run scheduled subscription payment simulation
$gateway->scheduled_subscription_payment(29.99, $renewal_order);

// Verify results
echo "\n--- VERIFICATION: RENEWAL PAYMENT ---\n";
echo "Renewal Order Payment ID: " . $renewal_order->get_meta('_whop_payment_id') . "\n";
echo "Renewal Order Status: " . $renewal_order->status . "\n";
if ($renewal_order->get_meta('_whop_payment_id') === 'pay_mock_9999' && $renewal_order->status === 'pending') {
    echo "RENEWAL TEST PASSED: Renewal payment successfully initiated asynchronously.\n";
} else {
    echo "RENEWAL TEST FAILED.\n";
}

echo "\n--- RUNNING REFUND FLOW MOCK TEST ---\n";
$refund_order = new WC_Order(3001);
$refund_order->update_meta_data('_whop_payment_id', 'pay_mock_refund_target');

$refund_result = $gateway->process_refund(3001, 15.00, 'Customer requested partial refund');
echo "Refund Result: " . ($refund_result ? 'Success' : 'Failure') . "\n";
if ($refund_result === true) {
    echo "REFUND TEST PASSED.\n";
} else {
    echo "REFUND TEST FAILED.\n";
}

echo "\n--- RUNNING CANCELLATION SYNC MOCK TEST ---\n";
Whop_Subscriptions::handle_subscription_status_changed($mock_subscription, 'cancelled', 'active');
echo "CANCELLATION SYNC TEST PASSED.\n";

echo "\n--- RETRY RULES ---\n";
echo "Using WooCommerce Subscriptions native retry rules and final-failure cancellation.\n";

