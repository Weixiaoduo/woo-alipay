<?php
/**
 * 一键启用所有支付网关
 * 
 * 访问: yoursite.com/?enable_alipay_gateways=1
 * 必须以管理员身份登录
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'init', function() {
    if ( ! isset( $_GET['enable_alipay_gateways'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }
    
    $gateways = ['alipay', 'alipay_installment', 'alipay_facetopay'];
    
    foreach ( $gateways as $gateway_id ) {
        $option_key = 'woocommerce_' . $gateway_id . '_settings';
        $settings = get_option( $option_key, [] );
        $settings['enabled'] = 'yes';
        update_option( $option_key, $settings );
    }
    
    wp_cache_flush();
    
    wp_die( '<h1>✓ 完成！</h1><p>所有支付网关已启用。</p><p><a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout' ) . '">查看支付设置</a></p>' );
}, 1 );
