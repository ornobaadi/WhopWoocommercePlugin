<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
    return;
}

final class WC_Whop_Blocks_Support extends AbstractPaymentMethodType {
    private $gateway;
    protected $name = 'whop';

    public function initialize() {
        $this->settings = get_option('woocommerce_whop_settings', []);
        $this->gateway = new WC_Gateway_Whop();
    }

    public function is_active() {
        return $this->gateway->is_available();
    }

    public function get_payment_method_script_handles() {
        wp_register_script(
            'wc-whop-blocks-integration',
            plugin_dir_url(dirname(__FILE__)) . 'assets/js/whop-blocks.js',
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ],
            WC_WHOP_VERSION,
            true
        );

        return ['wc-whop-blocks-integration'];
    }

    public function get_payment_method_data() {
        return [
            'title' => $this->get_setting('title', 'Whop'),
            'description' => $this->get_setting('description', 'Pay securely with Whop.'),
            'supports' => array('products', 'refunds', 'subscriptions'),
        ];
    }
}
