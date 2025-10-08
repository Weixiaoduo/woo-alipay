<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WC_Alipay_Subscription_UI {
	public static function init() {
		// Thank You 页签约提示
		add_action( 'woocommerce_thankyou', [ __CLASS__, 'maybe_render_thankyou_sign' ], 50 );

		// 订阅详情页签约按钮（Woo Subscriptions）
		add_action( 'woocommerce_account_view-subscription_endpoint', [ __CLASS__, 'render_view_subscription_sign' ] );
	}

	protected static function can_offer_sign( $user_id ) {
		if ( ! class_exists( 'WC_Alipay_Agreement' ) ) { return false; }
		$enabled = get_option( 'woocommerce_alipay_settings', [] )['enable_auto_renew'] ?? 'no';
		if ( 'yes' !== $enabled ) { return false; }
		return ! WC_Alipay_Agreement::has_user_agreement( $user_id );
	}

	public static function maybe_render_thankyou_sign( $order_id ) {
		if ( ! is_user_logged_in() || ! $order_id ) { return; }
		$user_id = get_current_user_id();
		if ( ! self::can_offer_sign( $user_id ) ) { return; }

		// 若订单包含订阅则更强提示（没有函数也不阻塞）
		if ( function_exists( 'wcs_order_contains_subscription' ) && ! wcs_order_contains_subscription( $order_id ) ) {
			return; // 仅在订阅相关订单的 Thank You 显示
		}

		$return_url = wc_get_endpoint_url( 'view-subscription', '', wc_get_page_permalink( 'myaccount' ) );
		$sign_url = add_query_arg( [ 'return_url' => rawurlencode( $return_url ) ], WC()->api_request_url( 'WC_Alipay_Agreement_Start' ) );
		echo '<div class="woocommerce-message" style="margin-top:15px">'
			. esc_html__( '为确保到期自动续费，请授权支付宝周期扣款。', 'woo-alipay' )
			. ' <a class="button" href="' . esc_url( $sign_url ) . '">' . esc_html__( '授权支付宝自动续费', 'woo-alipay' ) . '</a>'
			. '</div>';
	}

	public static function render_view_subscription_sign( $subscription_id ) {
		if ( ! is_user_logged_in() ) { return; }
		$user_id = get_current_user_id();
		if ( ! self::can_offer_sign( $user_id ) ) { return; }

		$return_url = wc_get_account_endpoint_url( 'subscriptions' );
		$sign_url = add_query_arg( [ 'return_url' => rawurlencode( $return_url ) ], WC()->api_request_url( 'WC_Alipay_Agreement_Start' ) );

		echo '<div class="woocommerce-info" style="margin:15px 0">'
			. '<p>' . esc_html__( '尚未授权支付宝自动续费。授权后，到期将自动从你绑定的支付宝扣款。', 'woo-alipay' ) . '</p>'
			. '<p><a class="button" href="' . esc_url( $sign_url ) . '">' . esc_html__( '立即授权', 'woo-alipay' ) . '</a></p>'
			. '</div>';
	}
}

WC_Alipay_Subscription_UI::init();
