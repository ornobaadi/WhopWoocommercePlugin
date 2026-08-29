<?php
/**
 * Whop Checkout Extensions
 */

if (!defined('ABSPATH')) {
    exit;
}

class Whop_Checkout {

    /**
     * Initialize checkout hooks.
     */
    public static function init() {
        // Add checkout field
        add_filter('woocommerce_checkout_fields', array(__CLASS__, 'add_instagram_field'));

        // Add the same field to the WooCommerce Checkout Block.
        add_action('woocommerce_init', array(__CLASS__, 'register_block_field'));

        // Validate checkout field
        add_action('woocommerce_checkout_process', array(__CLASS__, 'validate_instagram_field'));

        // Save checkout field to order meta
        add_action('woocommerce_checkout_update_order_meta', array(__CLASS__, 'save_instagram_field'));

        // Copy the block field into the existing order meta used by the plugin.
        add_action('woocommerce_set_additional_field_value', array(__CLASS__, 'save_block_field'), 10, 4);
    }

    /**
     * Register the Instagram field for the WooCommerce Checkout Block.
     */
    public static function register_block_field() {
        if (!function_exists('woocommerce_register_additional_checkout_field')) {
            return;
        }

        woocommerce_register_additional_checkout_field(array(
            'id'                => 'whop-woocommerce/instagram-username',
            'label'             => __('Instagram Username', 'whop-woocommerce'),
            'location'          => 'contact',
            'type'              => 'text',
            'required'          => true,
            'attributes'        => array(
                'autocomplete' => 'off',
                'maxLength'    => 150,
            ),
            'sanitize_callback' => array(__CLASS__, 'sanitize_instagram_username'),
        ));
    }

    /**
     * Normalize an Instagram username from either checkout implementation.
     *
     * @param string $username Submitted username.
     * @return string
     */
    public static function sanitize_instagram_username($username) {
        $username = trim(sanitize_text_field($username));
        return $username && '@' !== $username[0] ? '@' . $username : $username;
    }

    /**
     * Copy the Checkout Block value to the plugin's existing order meta key.
     *
     * @param string   $key       Additional field ID.
     * @param string   $value     Submitted value.
     * @param string   $group     Additional field group.
     * @param WC_Data  $wc_object WooCommerce object receiving the field.
     */
    public static function save_block_field($key, $value, $group, $wc_object) {
        if ('whop-woocommerce/instagram-username' !== $key || 'other' !== $group || !is_a($wc_object, 'WC_Order')) {
            return;
        }

        $wc_object->update_meta_data('_order_instagram_username', self::sanitize_instagram_username($value));
    }

    /**
     * Inject Instagram Username field into checkout billing section.
     *
     * @param array $fields Existing checkout fields.
     * @return array Updated checkout fields.
     */
    public static function add_instagram_field($fields) {
        $fields['billing']['instagram_username'] = array(
            'type'        => 'text',
            'label'       => __('Instagram Username', 'whop-woocommerce'),
            'placeholder' => __('@username', 'whop-woocommerce'),
            'required'    => true,
            'class'       => array('form-row-wide'),
            'clear'       => true,
            'priority'    => 120,
        );
        return $fields;
    }

    /**
     * Validate Instagram Username field input.
     */
    public static function validate_instagram_field() {
        if (empty($_POST['billing_instagram_username'])) {
            wc_add_notice(__('<strong>Instagram Username</strong> is a required field.', 'whop-woocommerce'), 'error');
        }
    }

    /**
     * Save Instagram Username field to order metadata.
     *
     * @param int $order_id The Order ID.
     */
    public static function save_instagram_field($order_id) {
        if (!empty($_POST['billing_instagram_username'])) {
            $username = self::sanitize_instagram_username(wp_unslash($_POST['billing_instagram_username']));

            // Save to order metadata
            $order = wc_get_order($order_id);
            if ($order) {
                $order->update_meta_data('_order_instagram_username', $username);
                $order->save();
                Whop_Logger::log("Saved Instagram username '{$username}' for Order #{$order_id}.", 'info');
            }
        }
    }
}

// Hook registration
Whop_Checkout::init();
