<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
final class WC_Payflex_Blocks extends AbstractPaymentMethodType {
    private $gateway;
    protected $name = 'payflex';
    public function initialize() {
        $this->settings = get_option( 'woocommerce_payflex_settings', [] );
        $this->gateway = new WC_Gateway_PartPay();
    }
    public function is_active() {
        return $this->gateway->is_available();
    }
    public function get_payment_method_script_handles() {
        wp_register_script(
            'wc-payflex-blocks-integration',
            plugin_dir_url(__FILE__) . '../assets/checkout.js',
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ],
            null,
            true
        );

        wp_register_script(
            'wc-payflex-eligibility',
            plugin_dir_url(__FILE__) . '../assets/payflex-eligibility.js',
            [
                'wc-blocks-checkout',
                'wp-element',
                'wp-plugins',
            ],
            filemtime(plugin_dir_path(__FILE__) . '../assets/payflex-eligibility.js'),
            true
        );

        return [ 'wc-payflex-blocks-integration', 'wc-payflex-eligibility' ];
    }
    public function get_payment_method_data() {
        return [
            'title' => $this->gateway->title,
            'description' => $this->gateway->description,
            // array_filter() preserves keys, and a gap makes wp_json_encode() emit an
            // object. The blocks checkout calls .includes() on this, so re-index it.
            'supports' => array_values(array_filter($this->gateway->supports, [$this->gateway, 'supports'])),
        ];
    }
}