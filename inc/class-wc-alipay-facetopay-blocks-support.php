<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * 当面付支付的 WooCommerce Blocks 支持类
 */
final class WC_Alipay_FaceToPay_Blocks_Support extends AbstractPaymentMethodType {

    private $gateway;
    protected $name = 'alipay_facetopay';

    public function __construct() {
        $this->name = 'alipay_facetopay';
    }

    public function initialize() {
        $this->settings = get_option( 'woocommerce_alipay_facetopay_settings', array() );
        
        $gateways = WC()->payment_gateways->payment_gateways();
        $this->gateway = isset( $gateways['alipay_facetopay'] ) ? $gateways['alipay_facetopay'] : false;
    }

    public function is_active() {
        $enabled = isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : 'no';
        return 'yes' === $enabled;
    }

    public function get_payment_method_script_handles() {
        $script_path = 'js/frontend/blocks-facetopay.js';
        $script_asset_path = WOO_ALIPAY_PLUGIN_PATH . 'js/frontend/blocks-facetopay.asset.php';
        $script_asset = file_exists( $script_asset_path )
            ? require( $script_asset_path )
            : array(
                'dependencies' => array( 'wc-blocks-registry', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
                'version'      => WOO_ALIPAY_VERSION
            );
        $script_url = trailingslashit( WOO_ALIPAY_PLUGIN_URL ) . $script_path;

        wp_register_script(
            'wc-alipay-facetopay-payments-blocks',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations( 'wc-alipay-facetopay-payments-blocks', 'woo-alipay', WOO_ALIPAY_PLUGIN_PATH . 'languages' );
        }

        return [ 'wc-alipay-facetopay-payments-blocks' ];
    }

    public function get_payment_method_script_handles_for_admin() {
        return $this->get_payment_method_script_handles();
    }

    public function get_payment_method_data() {
        return [
            'title'       => $this->get_setting( 'title', '支付宝扫码支付' ),
            'description' => $this->get_setting( 'description', '使用支付宝扫描二维码完成支付' ),
            'supports'    => $this->get_supported_features(),
            'icon'        => WOO_ALIPAY_PLUGIN_URL . 'assets/images/alipay-icon.svg',
        ];
    }

    public function get_supported_features() {
        return $this->gateway ? $this->gateway->supports : ['products'];
    }
}
