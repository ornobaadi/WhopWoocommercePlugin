<?php
/**
 * Whop Logger Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class Whop_Logger {

    /**
     * WC Logger instance.
     *
     * @var WC_Logger
     */
    private static $logger = null;

    /**
     * Get the WC Logger instance.
     *
     * @return WC_Logger
     */
    private static function get_logger() {
        if (self::$logger === null && class_exists('WC_Logger')) {
            self::$logger = wc_get_logger();
        }
        return self::$logger;
    }

    /**
     * Log a message.
     *
     * @param string $message The message to log.
     * @param string $level   Log level (debug, info, notice, warning, error, critical, alert, emergency).
     */
    public static function log($message, $level = 'info') {
        $settings = get_option('woocommerce_whop_settings', array());
        $debug_enabled = isset($settings['debug_log']) && 'yes' === $settings['debug_log'];

        if ('debug' === $level && !$debug_enabled) {
            return;
        }

        $logger = self::get_logger();
        if ($logger) {
            // Clean sensitive data before logging
            $clean_message = self::sanitize_log_message($message);
            $logger->log($level, $clean_message, array('source' => 'whop-woocommerce'));
        }
    }

    /**
     * Sanitize log message to remove sensitive keys/tokens.
     *
     * @param string|array|object $message Message to sanitize.
     * @return string Sanitized log message.
     */
    private static function sanitize_log_message($message) {
        if (is_array($message) || is_object($message)) {
            $message = print_r($message, true);
        }

        // Mask API keys and secrets
        $patterns = array(
            '/bearer\s+[a-zA-Z0-9_\-]+/i' => 'Bearer [MASKED]',
            '/whop_[a-zA-Z0-9_\-]+/i' => 'whop_[MASKED]',
            '/("api_key"|"secret"|"webhook_secret"|"token")\s*:\s*"[^"]+"/i' => '$1:"[MASKED]"',
        );

        return preg_replace(array_keys($patterns), array_values($patterns), $message);
    }
}
