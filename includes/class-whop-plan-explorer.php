<?php
/**
 * Quick Helper to query Whop API for all products, plans, and their real cashier checkout links.
 * 
 * Access this via browser as admin: /wp-admin/admin.php?page=whop-plan-explorer
 */

if (!defined('ABSPATH')) {
    exit;
}

class Whop_Plan_Explorer {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu_page'));
    }

    public static function add_menu_page() {
        add_submenu_page(
            'woocommerce',
            'Whop Plans Explorer',
            'Whop Plans Explorer',
            'manage_woocommerce',
            'whop-plan-explorer',
            array(__CLASS__, 'render_page')
        );
    }

    public static function render_page() {
        $settings   = get_option('woocommerce_whop_settings', array());
        $company_id = isset($settings['company_id']) ? trim($settings['company_id']) : '';
        $api_key    = isset($settings['api_key']) ? trim($settings['api_key']) : '';

        echo '<div class="wrap">';
        echo '<h1>Whop Plans & Direct Checkout Links</h1>';

        if (empty($api_key)) {
            echo '<div class="notice notice-error"><p>Please configure your Whop API Key in WooCommerce -> Settings -> Payments -> Whop first.</p></div>';
            echo '</div>';
            return;
        }

        echo '<p>Below are your subscription plans fetched live from Whop API, including the exact <strong>Plan ID (plan_...)</strong> and direct <strong>Cashier Checkout URL</strong> for each.</p>';

        // Query Whop API plans endpoint with company_id / account_id
        $endpoint = '/plans';
        if (!empty($company_id)) {
            $endpoint .= '?company_id=' . urlencode($company_id) . '&account_id=' . urlencode($company_id);
        }

        $response = Whop_API::request($endpoint, 'GET');

        if (is_wp_error($response)) {
            echo '<div class="notice notice-error"><p>API Error: ' . esc_html($response->get_error_message()) . '</p></div>';
            echo '</div>';
            return;
        }

        $plans = isset($response['data']) ? $response['data'] : (is_array($response) ? $response : array());

        if (empty($plans)) {
            echo '<p>No plans found under your Whop company account.</p>';
            echo '<details><summary>Debug Raw Response</summary><pre>' . esc_html(wp_json_encode($response, JSON_PRETTY_PRINT)) . '</pre></details>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped" style="margin-top:20px; max-width:1100px;">';
        echo '<thead>';
        echo '<tr>';
        echo '<th style="padding:10px;">Plan / Product Title</th>';
        echo '<th style="padding:10px;">Pricing / Interval</th>';
        echo '<th style="padding:10px;">Plan ID (plan_...)</th>';
        echo '<th style="padding:10px;">Direct Cashier Link</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ($plans as $plan) {
            $plan_id    = isset($plan['id']) ? $plan['id'] : '';
            $plan_title = !empty($plan['title']) ? $plan['title'] : (!empty($plan['internal_notes']) ? $plan['internal_notes'] : (!empty($plan['product']['name']) ? $plan['product']['name'] : 'Subscription Plan'));
            $plan_type  = isset($plan['plan_type']) ? $plan['plan_type'] : 'recurring';
            $price      = isset($plan['renewal_price']) ? $plan['renewal_price'] : (isset($plan['initial_price']) ? $plan['initial_price'] : 0);
            $billing_period = isset($plan['billing_period']) ? $plan['billing_period'] : 'period';
            $trial_days = !empty($plan['trial_period_days']) ? $plan['trial_period_days'] . '-day trial' : 'No trial';
            $plan_link  = 'https://whop.com/checkout/' . $plan_id;

            echo '<tr>';
            echo '<td style="padding:10px;"><strong>' . esc_html($plan_title) . '</strong></td>';
            echo '<td style="padding:10px;">$' . esc_html($price) . ' / ' . esc_html($billing_period) . ' (' . esc_html($trial_days) . ')</td>';
            echo '<td style="padding:10px;"><input type="text" readonly value="' . esc_attr($plan_id) . '" style="width:100%; font-family:monospace; background:#fff;" onclick="this.select(); document.execCommand(\'copy\'); alert(\'Copied Plan ID: ' . esc_attr($plan_id) . '\');"></td>';
            echo '<td style="padding:10px;"><a href="' . esc_url($plan_link) . '" target="_blank" class="button button-primary">Open Cashier &rarr;</a></td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }
}

Whop_Plan_Explorer::init();
