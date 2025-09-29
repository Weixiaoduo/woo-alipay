<?php
/*
* Plugin Name: Woo Alipay
* Plugin URI: https://wenpai.org/plugins/woo-alipay
* Description: Integrate Woocommerce with Alipay (Mainland China). Supports WooCommerce Blocks and HPOS.
* Version: 3.2.0
* Author: WooCN.com
* Author URI: https://woocn.com/
* Text Domain: woo-alipay
* Domain Path: /languages
* WC tested up to: 10.2.2
* Requires Plugins: woocommerce
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! defined( 'WOO_ALIPAY_PLUGIN_FILE' ) ) {
	define( 'WOO_ALIPAY_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'WOO_ALIPAY_PLUGIN_PATH' ) ) {
	define( 'WOO_ALIPAY_PLUGIN_PATH', plugin_dir_path( WOO_ALIPAY_PLUGIN_FILE ) );
}

if ( ! defined( 'WOO_ALIPAY_PLUGIN_URL' ) ) {
	define( 'WOO_ALIPAY_PLUGIN_URL', plugin_dir_url( WOO_ALIPAY_PLUGIN_FILE ) );
}

require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-woo-alipay.php';

add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

register_activation_hook( WOO_ALIPAY_PLUGIN_FILE, array( 'Woo_Alipay', 'activate' ) );
register_deactivation_hook( WOO_ALIPAY_PLUGIN_FILE, array( 'Woo_Alipay', 'deactivate' ) );
register_uninstall_hook( WOO_ALIPAY_PLUGIN_FILE, array( 'Woo_Alipay', 'uninstall' ) );

function woo_alipay_run() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	if ( file_exists( WOO_ALIPAY_PLUGIN_PATH . 'vendor/autoload.php' ) ) {
		require_once WOO_ALIPAY_PLUGIN_PATH . 'vendor/autoload.php';
	}

	require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-wc-alipay.php';

	$wc_alipay = new WC_Alipay( true );
	$wooalipay = new Woo_Alipay( array(), true );
}
add_action( 'init', 'woo_alipay_run', 0, 0 );
