<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WC_Alipay_Agreement {
	const META_USER_AGREEMENT_NO = '_alipay_agreement_no';
	const META_USER_AGREEMENT_STATUS = '_alipay_agreement_status';
	const META_USER_PENDING_EXTERNAL_SIGN_NO = '_alipay_pending_external_sign_no';

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_api_endpoints' ] );
	}

	public static function register_api_endpoints() {
		add_action( 'woocommerce_api_wc_alipay_agreement_notify', [ __CLASS__, 'handle_agreement_notify' ] );
		add_action( 'woocommerce_api_wc_alipay_agreement_return', [ __CLASS__, 'handle_agreement_return' ] );
		add_action( 'woocommerce_api_wc_alipay_agreement_start', [ __CLASS__, 'handle_agreement_start' ] );
	}

	public static function get_sign_url( $user_id = 0, $context = [] ) {
		$gateway = new WC_Alipay( false );
		$notify_url = add_query_arg( [], WC()->api_request_url( 'WC_Alipay_Agreement_Notify' ) );
		$return_url = add_query_arg( [], WC()->api_request_url( 'WC_Alipay_Agreement_Return' ) );

		require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
		require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayUserAgreementPageSignRequest.php';

		$aop = new AopClient();
		$aop->gatewayUrl        = ( 'yes' === $gateway->get_option( 'sandbox' ) ) ? WC_Alipay::GATEWAY_SANDBOX_URL : WC_Alipay::GATEWAY_URL;
		$aop->appId             = $gateway->get_option( 'appid' );
		$aop->rsaPrivateKey     = $gateway->get_option( 'private_key' );
		$aop->alipayrsaPublicKey= $gateway->get_option( 'public_key' );
		$aop->charset           = 'utf-8';
		$aop->signType          = 'RSA2';

		$external_sign_no = 'AGREE' . ( $user_id ? $user_id : get_current_user_id() ) . '-' . current_time( 'timestamp' );
		// 建立 external_sign_no 到用户的临时映射
		if ( $user_id ) {
			set_transient( 'woo_alipay_pending_user_' . $external_sign_no, absint( $user_id ), DAY_IN_SECONDS );
			update_user_meta( $user_id, self::META_USER_PENDING_EXTERNAL_SIGN_NO, $external_sign_no );
		}

		$personal_product_code = apply_filters( 'woo_alipay_agreement_personal_product_code', 'CYCLE_PAY_AUTH', $user_id );
		$sign_scene            = apply_filters( 'woo_alipay_agreement_sign_scene', 'INDUSTRY|DEFAULT', $user_id );

		$biz = [
			'external_sign_no'      => $external_sign_no,
			'personal_product_code' => $personal_product_code,
			'sign_scene'            => $sign_scene,
			'access_params'         => [ 'channel' => 'ALIPAYAPP' ],
			'merchant_process_url'  => $return_url,
			'notify_url'            => $notify_url,
		];
		if ( ! empty( $context['return_url'] ) ) {
			$biz['merchant_process_url'] = $context['return_url'];
		}

		$request = new AlipayUserAgreementPageSignRequest();
		$request->setBizContent( wp_json_encode( $biz ) );
		$request->setNotifyUrl( $notify_url );
		$request->setReturnUrl( $return_url );

		// pageExecute 返回跳转 URL/表单
		$sign_form_or_url = $aop->pageExecute( $request );
		return [ 'payload' => $sign_form_or_url, 'external_sign_no' => $external_sign_no ];
	}

	public static function handle_agreement_return() {
		// 用户浏览器跳转回站点，可用于展示成功页面；协议最终以异步通知为准
		wp_safe_redirect( home_url() );
		exit;
	}

	public static function handle_agreement_notify() {
		$gateway = new WC_Alipay( false );
		require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
		$aop = new AopClient();
		$aop->alipayrsaPublicKey= $gateway->get_option( 'public_key' );
		$aop->charset           = 'utf-8';
		$aop->signType          = 'RSA2';

		$params = wp_unslash( $_POST );
		$verified = $aop->rsaCheckV1( $params, $aop->alipayrsaPublicKey, $aop->signType );
		if ( ! $verified ) {
			echo 'fail';
			exit;
		}

		$agreement_no = isset( $params['agreement_no'] ) ? sanitize_text_field( $params['agreement_no'] ) : '';
		$alipay_user_id = isset( $params['alipay_user_id'] ) ? sanitize_text_field( $params['alipay_user_id'] ) : '';
		$status = isset( $params['status'] ) ? sanitize_text_field( $params['status'] ) : '';
		$external_sign_no = isset( $params['external_sign_no'] ) ? sanitize_text_field( $params['external_sign_no'] ) : '';

		// 根据 external_sign_no 还原用户
		$user_id = 0;
		if ( $external_sign_no ) {
			$user_id = absint( get_transient( 'woo_alipay_pending_user_' . $external_sign_no ) );
		}
		if ( ! $user_id && is_user_logged_in() ) {
			$user_id = get_current_user_id();
		}
		if ( $user_id ) {
			update_user_meta( $user_id, self::META_USER_AGREEMENT_NO, $agreement_no );
			update_user_meta( $user_id, self::META_USER_AGREEMENT_STATUS, $status );
			delete_transient( 'woo_alipay_pending_user_' . $external_sign_no );
			delete_user_meta( $user_id, self::META_USER_PENDING_EXTERNAL_SIGN_NO );
		}

		echo 'success';
		exit;
	}

	public static function get_user_agreement_no( $user_id ) {
		return get_user_meta( $user_id, self::META_USER_AGREEMENT_NO, true );
	}

	public static function has_user_agreement( $user_id ) {
		return (bool) self::get_user_agreement_no( $user_id );
	}

	public static function handle_agreement_start() {
		if ( ! is_user_logged_in() ) {
			wp_die( __( '请先登录后再进行签约授权。', 'woo-alipay' ) );
		}
		$user_id = get_current_user_id();
		$context = [];
		if ( isset( $_GET['return_url'] ) ) {
			$context['return_url'] = esc_url_raw( wp_unslash( $_GET['return_url'] ) );
		}
		$result = self::get_sign_url( $user_id, $context );
		if ( is_array( $result ) && ! empty( $result['payload'] ) ) {
			// 输出表单/HTML，通常将自动跳转到支付宝
			echo $result['payload'];
			exit;
		}
		wp_die( __( '无法生成签约请求，请稍后重试。', 'woo-alipay' ) );
	}

}

WC_Alipay_Agreement::init();
