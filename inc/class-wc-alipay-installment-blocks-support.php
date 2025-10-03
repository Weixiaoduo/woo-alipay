<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * 花呗分期支付的 WooCommerce Blocks 支持类
 */
final class WC_Alipay_Installment_Blocks_Support extends AbstractPaymentMethodType {

    private $gateway;
    protected $name = 'alipay_installment';

    public function __construct() {
        $this->name = 'alipay_installment';
    }

    public function initialize() {
        $this->settings = get_option( 'woocommerce_alipay_installment_settings', array() );
        
        $gateways = WC()->payment_gateways->payment_gateways();
        $this->gateway = isset( $gateways['alipay_installment'] ) ? $gateways['alipay_installment'] : false;
    }

    public function is_active() {
        $enabled = isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : 'no';
        return 'yes' === $enabled;
    }

    public function get_payment_method_script_handles() {
        $script_path = 'js/frontend/blocks-installment.js';
        $script_asset_path = WOO_ALIPAY_PLUGIN_PATH . 'js/frontend/blocks-installment.asset.php';
        $script_asset = file_exists( $script_asset_path )
            ? require( $script_asset_path )
            : array(
                'dependencies' => array( 'wc-blocks-registry', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
                'version'      => WOO_ALIPAY_VERSION
            );
        $script_url = trailingslashit( WOO_ALIPAY_PLUGIN_URL ) . $script_path;

        wp_register_script(
            'wc-alipay-installment-payments-blocks',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations( 'wc-alipay-installment-payments-blocks', 'woo-alipay', WOO_ALIPAY_PLUGIN_PATH . 'languages' );
        }

        return [ 'wc-alipay-installment-payments-blocks' ];
    }

    public function get_payment_method_script_handles_for_admin() {
        return $this->get_payment_method_script_handles();
    }

    public function get_payment_method_data() {
        $cart_total = WC()->cart ? WC()->cart->get_total('') : 0;
        $min_amount = $this->get_setting( 'min_amount', 100 );
        $available_periods = $this->get_setting( 'available_periods', array('3', '6', '12') );
        // 动态按阈值过滤
        $min6 = floatval($this->get_setting('min_amount_6', 0));
        $min12 = floatval($this->get_setting('min_amount_12', 0));
        $filtered_periods = array();
        foreach ($available_periods as $p) {
            if ($p === '6' && $min6 > 0 && $cart_total < $min6) { continue; }
            if ($p === '12' && $min12 > 0 && $cart_total < $min12) { continue; }
            $filtered_periods[] = $p;
        }
        $available_periods = $filtered_periods;
        $default_period = $this->get_setting( 'default_period', '3' );
        
        // 获取汇率并转换金额
        $current_currency = get_option('woocommerce_currency');
        $exchange_rate = $this->get_setting( 'exchange_rate', 7.0 );
        
        if (!in_array($current_currency, array('CNY', 'RMB'), true)) {
            $cart_total = floatval($cart_total) * floatval($exchange_rate);
        }
        
        $meets_min_amount = $cart_total >= floatval($min_amount);
        
        return [
            'title'            => $this->get_setting( 'title', '支付宝花呗分期' ),
            'description'      => $this->get_setting( 'description', '使用支付宝花呗分期付款，支持3期、6期、12期免息或低息分期。' ),
            'supports'         => $this->get_supported_features(),
            'icon'             => WOO_ALIPAY_PLUGIN_URL . 'assets/images/alipay-icon.svg',
            'minAmount'        => floatval($min_amount),
            'cartTotal'        => floatval($cart_total),
'meetsMinAmount'   => $meets_min_amount,
            'insufficientBehavior' => $this->get_setting( 'blocks_insufficient_behavior', 'hide' ),
            'availablePeriods' => $available_periods,
'defaultPeriod'    => $default_period,
'feePayer'         => ($this->get_setting('fee_bearer', 'user') === 'seller' ? 'seller' : 'user'),
            'showInterestFreeBadge' => ($this->get_setting('show_interest_free_badge', 'yes') === 'yes'),
        ];
    }

    public function get_supported_features() {
        return $this->gateway ? $this->gateway->supports : ['products'];
    }
}
