<?php
/**
 * Whop API Client
 */

if (!defined('ABSPATH')) {
    exit;
}

class Whop_API {

    /**
     * Whop production API endpoint.
     */
    private const API_URL = 'https://api.whop.com/api/v1';

    /**
     * Get the API Key from settings.
     *
     * @return string
     */
    private static function get_api_key() {
        $settings = get_option('woocommerce_whop_settings', array());
        return isset($settings['api_key']) ? $settings['api_key'] : '';
    }

    /**
     * Get the Company ID from settings.
     *
     * @return string
     */
    private static function get_company_id() {
        $settings = get_option('woocommerce_whop_settings', array());
        return isset($settings['company_id']) ? $settings['company_id'] : '';
    }

    /**
     * Perform HTTP request to Whop API.
     *
     * @param string $endpoint The API endpoint path (e.g. '/checkout_configurations').
     * @param string $method   HTTP method (GET, POST, etc.).
     * @param array  $body     Request body data.
     * @return array|WP_Error  Decoded response body or WP_Error on failure.
     */
    public static function request($endpoint, $method = 'GET', $body = array()) {
        $api_key = self::get_api_key();

        if (empty($api_key)) {
            Whop_Logger::log('Whop API Key is not configured.', 'error');
            return new WP_Error('whop_api_error', __('Whop API Key is not configured.', 'whop-woocommerce'));
        }

        $url = self::API_URL . $endpoint;
        
        $headers = array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        );

        $args = array(
            'method'      => $method,
            'headers'     => $headers,
            'timeout'     => 30,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking'    => true,
        );

        if (!empty($body) && in_array($method, array('POST', 'PUT', 'PATCH'))) {
            $args['body'] = wp_json_encode($body);
        }

        Whop_Logger::log("Sending request to Whop API: {$method} {$url}", 'debug');
        if (!empty($args['body'])) {
            Whop_Logger::log("Request Body: " . $args['body'], 'debug');
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            Whop_Logger::log("Whop API Request failed: {$error_message}", 'error');
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        Whop_Logger::log("Whop API Response code: {$status_code}", 'debug');
        Whop_Logger::log("Whop API Response body: {$response_body}", 'debug');

        if ($status_code < 200 || $status_code >= 300) {
            $error_data = json_decode($response_body, true);
            $error_message = isset($error_data['error']['message'])
                ? $error_data['error']['message']
                : $response_body;

            return new WP_Error(
                'whop_api_error',
                sprintf(__('Whop API returned status code %1$d: %2$s', 'whop-woocommerce'), $status_code, $error_message),
                array('status_code' => $status_code, 'body' => $response_body)
            );
        }

        $data = json_decode($response_body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('whop_api_json_error', __('Failed to parse JSON response from Whop API.', 'whop-woocommerce'));
        }

        return $data;
    }

    /**
     * Create checkout configuration.
     *
     * @param array $args Checkout parameters.
     * @return array|WP_Error
     */
    public static function create_checkout_configuration($args) {
        $company_id = self::get_company_id();
        if (empty($company_id)) {
            return new WP_Error('whop_api_error', __('Whop Company ID is not configured.', 'whop-woocommerce'));
        }

        // Inline payment plans require the company ID inside the plan object.
        if (!isset($args['plan']['company_id'])) {
            $args['plan']['company_id'] = $company_id;
        }

        return self::request('/checkout_configurations', 'POST', $args);
    }

    /**
     * Retrieve payment information.
     *
     * @param string $payment_id The payment ID.
     * @return array|WP_Error
     */
    public static function retrieve_payment($payment_id) {
        return self::request('/payments/' . urlencode($payment_id), 'GET');
    }

    /**
     * Charge a saved payment method (off-session payment).
     *
     * @param array $args Charge parameters.
     * @return array|WP_Error
     */
    public static function create_off_session_payment($args) {
        // Ensure company_id is provided
        if (!isset($args['company_id'])) {
            $args['company_id'] = self::get_company_id();
        }
        return self::request('/payments', 'POST', $args);
    }

    /**
     * Refund a payment.
     *
     * @param string $payment_id Payment ID to refund.
     * @param float  $amount     Amount to refund.
     * @return array|WP_Error
     */
    public static function refund_payment($payment_id, $amount) {
        $body = array(
            'amount' => $amount,
        );
        return self::request('/payments/' . urlencode($payment_id) . '/refund', 'POST', $body);
    }

    /**
     * Retrieve product details (including plans).
     *
     * @param string $product_id Product ID (e.g. prod_...).
     * @return array|WP_Error
     */
    public static function retrieve_product($product_id) {
        return self::request('/products/' . urlencode($product_id), 'GET');
    }

    /**
     * Retrieve membership details.
     *
     * @param string $membership_id Membership ID.
     * @return array|WP_Error
     */
    public static function retrieve_membership($membership_id) {
        return self::request('/memberships/' . urlencode($membership_id), 'GET');
    }

    /**
     * Cancel a membership.
     *
     * @param string $membership_id Membership ID.
     * @return array|WP_Error
     */
    public static function cancel_membership($membership_id, $cancel_at_period_end = true) {
        return self::request(
            '/memberships/' . urlencode($membership_id) . '/cancel',
            'POST',
            array('cancel_at_period_end' => (bool) $cancel_at_period_end)
        );
    }
}
