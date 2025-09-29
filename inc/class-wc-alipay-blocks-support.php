<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Alipay_Blocks_Support extends AbstractPaymentMethodType {

    private $gateway;
    protected $name = 'alipay';

    public function __construct() {
        $this->name = 'alipay';
    }

    public function initialize() {
        $this->settings = get_option( 'woocommerce_alipay_settings', array() );
        
        $gateways = WC()->payment_gateways->payment_gateways();
        $this->gateway = isset( $gateways['alipay'] ) ? $gateways['alipay'] : false;
    }

    public function is_active() {
        $enabled = isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : 'no';
        return 'yes' === $enabled;
    }

    public function get_payment_method_script_handles() {
        $script_path = 'js/frontend/blocks.js';
        $script_asset_path = WOO_ALIPAY_PLUGIN_PATH . 'js/frontend/blocks.asset.php';
        $script_asset = file_exists( $script_asset_path )
            ? require( $script_asset_path )
            : array(
                'dependencies' => array( 'wc-blocks-registry', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
                'version'      => '3.1.0'
            );
        $script_url = trailingslashit( WOO_ALIPAY_PLUGIN_URL ) . $script_path;

        wp_register_script(
            'wc-alipay-payments-blocks',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations( 'wc-alipay-payments-blocks', 'woo-alipay', WOO_ALIPAY_PLUGIN_PATH . 'languages' );
        }

        return [ 'wc-alipay-payments-blocks' ];
    }

    public function get_payment_method_script_handles_for_admin() {
        return $this->get_payment_method_script_handles();
    }

    public function get_payment_method_data() {
        return [
            'title'       => $this->get_setting( 'title', '支付宝' ),
            'description' => $this->get_setting( 'description', '通过支付宝付款（中国大陆，包括香港和澳门）。' ),
            'supports'    => $this->get_supported_features(),
            'icon'        => '',
        ];
    }

    public function get_supported_features() {
        return $this->gateway ? $this->gateway->supports : [];
    }
}