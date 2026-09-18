<?php
/**
 * Whop Product Fields Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class Whop_Product_Fields {

    /**
     * Initialize product meta fields hooks.
     */
    public static function init() {
        // Simple and Subscription products (General tab)
        add_action('woocommerce_product_options_general_product_data', array(__CLASS__, 'add_product_fields'));
        add_action('woocommerce_process_product_meta', array(__CLASS__, 'save_product_fields'));

        // Variable products (Variation settings)
        add_action('woocommerce_product_after_variable_attributes', array(__CLASS__, 'add_variation_fields'), 10, 3);
        add_action('woocommerce_save_product_variation', array(__CLASS__, 'save_variation_fields'), 10, 2);
    }

    /**
     * Add Whop Plan ID / URL field to product general tab.
     */
    public static function add_product_fields() {
        echo '<div class="options_group show_if_simple show_if_subscription">';

        woocommerce_wp_text_input(
            array(
                'id'          => '_whop_plan_id',
                'label'       => __('Whop Product ID or Plan ID', 'whop-woocommerce'),
                'placeholder' => __('e.g. prod_jbJDGWgC6jBVC or plan_...', 'whop-woocommerce'),
                'desc_tip'    => true,
                'description' => __('Enter your Whop Product ID (prod_...) or Plan ID (plan_...). The plugin will automatically connect the recurring plan with Whop cashier checkout.', 'whop-woocommerce'),
            )
        );

        echo '</div>';
    }

    /**
     * Save Whop Plan ID field for simple/subscription products.
     *
     * @param int $post_id Product ID.
     */
    public static function save_product_fields($post_id) {
        if (isset($_POST['_whop_plan_id'])) {
            $value = sanitize_text_field(wp_unslash($_POST['_whop_plan_id']));
            update_post_meta($post_id, '_whop_plan_id', $value);
        }
    }

    /**
     * Add Whop Plan ID field to product variations.
     *
     * @param int     $loop           Position in the loop.
     * @param array   $variation_data Variation data array.
     * @param WP_Post $variation      Variation post object.
     */
    public static function add_variation_fields($loop, $variation_data, $variation) {
        $value = get_post_meta($variation->ID, '_whop_plan_id', true);

        woocommerce_wp_text_input(
            array(
                'id'            => "_whop_plan_id_{$loop}",
                'name'          => "_whop_variation_plan_id[{$variation->ID}]",
                'value'         => $value,
                'label'         => __('Whop Plan ID / URL', 'whop-woocommerce'),
                'desc_tip'      => true,
                'description'   => __('Enter the Whop Plan ID or Whop Product URL for this variation.', 'whop-woocommerce'),
                'wrapper_class' => 'form-row form-row-full',
                'placeholder'   => __('e.g. plan_abc123 or https://whop.com/...', 'whop-woocommerce'),
            )
        );
    }

    /**
     * Save Whop Plan ID field for variations.
     *
     * @param int $variation_id Variation ID.
     * @param int $i            Index.
     */
    public static function save_variation_fields($variation_id, $i) {
        if (isset($_POST['_whop_variation_plan_id'][$variation_id])) {
            $value = sanitize_text_field(wp_unslash($_POST['_whop_variation_plan_id'][$variation_id]));
            update_post_meta($variation_id, '_whop_plan_id', $value);
        }
    }

    /**
     * Helper to retrieve Whop Plan ID or URL for a product or variation.
     *
     * @param WC_Product|int $product Product object or ID.
     * @return string
     */
    public static function get_whop_plan_id($product) {
        if (is_numeric($product)) {
            $product = wc_get_product($product);
        }

        if (!$product) {
            return '';
        }

        $plan_id = $product->get_meta('_whop_plan_id');

        // If variation without own plan ID, check parent product
        if (empty($plan_id) && $product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) {
                $plan_id = $parent->get_meta('_whop_plan_id');
            }
        }

        return trim((string) $plan_id);
    }
}

Whop_Product_Fields::init();
