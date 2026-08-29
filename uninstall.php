<?php
/**
 * Whop Payment Gateway Uninstall
 *
 * Uninstalling the plugin removes all configuration options.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete options
delete_option('woocommerce_whop_settings');
delete_option('whop_webhook_events');
