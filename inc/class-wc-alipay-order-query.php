<?php
/**
 * Alipay Order Query Class
 * 
 * Handles querying order payment status from Alipay
 * 
 * @package Woo_Alipay
 * @since 3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WC_Alipay_Order_Query {

	/**
	 * Gateway instance
	 *
	 * @var WC_Alipay
	 */
	private $gateway;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// Schedule cron job for checking pending orders
		add_action( 'wp', array( $this, 'schedule_order_status_check' ) );
		add_action( 'woo_alipay_check_pending_orders', array( $this, 'check_pending_orders' ) );
		
		// AJAX endpoint for manual order status check
		add_action( 'wp_ajax_woo_alipay_query_order_status', array( $this, 'ajax_query_order_status' ) );
		add_action( 'wp_ajax_nopriv_woo_alipay_query_order_status', array( $this, 'ajax_query_order_status' ) );
	}

	/**
	 * Schedule cron job
	 */
	public function schedule_order_status_check() {
		$gateway = $this->get_gateway();
		
		// Check if feature is enabled
		if ( ! $gateway || 'yes' !== $gateway->get_option( 'enable_order_query', 'yes' ) ) {
			// Unschedule if disabled
			$timestamp = wp_next_scheduled( 'woo_alipay_check_pending_orders' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'woo_alipay_check_pending_orders' );
			}
			return;
		}
		
		// Get interval from settings
		$interval = absint( $gateway->get_option( 'order_query_interval', '5' ) );
		$schedule = 'woo_alipay_' . $interval . 'min';
		
		if ( ! wp_next_scheduled( 'woo_alipay_check_pending_orders' ) ) {
			wp_schedule_event( time(), $schedule, 'woo_alipay_check_pending_orders' );
		}
	}

	/**
	 * Check pending orders
	 */
	public function check_pending_orders() {
		$gateway = $this->get_gateway();
		
		// Check if feature is enabled
		if ( ! $gateway || 'yes' !== $gateway->get_option( 'enable_order_query', 'yes' ) ) {
			return;
		}
		
		// Get time range from settings
		$time_range_hours = absint( $gateway->get_option( 'order_query_time_range', '24' ) );
		$time_range_seconds = $time_range_hours * HOUR_IN_SECONDS;
		
		// Get pending/on-hold orders that use Alipay
		$args = array(
			'status'         => array( 'pending', 'on-hold' ),
			'payment_method' => 'alipay',
			'limit'          => 50,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'date_created'   => '>' . ( time() - $time_range_seconds ),
		);

		$orders = wc_get_orders( $args );

		foreach ( $orders as $order ) {
			$this->query_single_order_status( $order );
			
			// Sleep for a bit to avoid rate limiting
			sleep( 1 );
		}
	}

	/**
	 * Query single order status from Alipay
	 *
	 * @param WC_Order $order
	 * @return bool|WP_Error
	 */
	public function query_single_order_status( $order ) {
		if ( ! $order || $order->is_paid() ) {
			return false;
		}

		$gateway = $this->get_gateway();
		if ( ! $gateway ) {
			return new WP_Error( 'gateway_not_found', __( 'Alipay gateway not found', 'woo-alipay' ) );
		}

		// Get the out_trade_no from order meta
		$out_trade_no = $order->get_meta( 'alipay_initalRequest' );
		
		if ( empty( $out_trade_no ) ) {
			// Order hasn't been sent to Alipay yet
			return false;
		}

		try {
			Woo_Alipay::require_lib( 'dummy_query' );

			$config = $this->get_config( $order->get_id() );
			$aop = new AlipayTradeService( $config );
			
			$biz_content = json_encode( array(
				'out_trade_no' => $out_trade_no,
			) );
			
			$request = new AlipayTradeQueryRequest();
			$request->setBizContent( $biz_content );

			$response = $aop->aopclientRequestExecute( $request );
			$response = $response->alipay_trade_query_response;

			if ( ! is_object( $response ) ) {
				return new WP_Error( 'invalid_response', __( 'Invalid response from Alipay', 'woo-alipay' ) );
			}

			// Log the query
			$this->log( 'Order #' . $order->get_id() . ' status query response: ' . wc_print_r( $response, true ) );

			// Process the response
			if ( '10000' === $response->code ) {
				// Query successful
				$trade_status = isset( $response->trade_status ) ? $response->trade_status : '';
				$trade_no = isset( $response->trade_no ) ? $response->trade_no : '';

				if ( 'TRADE_SUCCESS' === $trade_status || 'TRADE_FINISHED' === $trade_status ) {
					// Payment successful
					$order->payment_complete( $trade_no );
					$order->add_order_note( 
						sprintf( 
							__( 'Payment status confirmed via query. Trade Status: %s', 'woo-alipay' ), 
							$trade_status 
						) 
					);

					if ( 'TRADE_FINISHED' === $trade_status ) {
						$order->update_meta_data( 'alipay_transaction_closed', true );
						$order->save_meta_data();
					}

					return true;
				} elseif ( 'TRADE_CLOSED' === $trade_status ) {
					// Trade closed
					$order->update_status( 'cancelled', __( 'Alipay trade closed', 'woo-alipay' ) );
					$order->update_meta_data( 'alipay_transaction_closed', true );
					$order->save_meta_data();
					
					return true;
				} elseif ( 'WAIT_BUYER_PAY' === $trade_status ) {
					// Still waiting for payment
					$order->add_order_note( __( 'Payment status: Still waiting for buyer to pay', 'woo-alipay' ) );
					return false;
				}
			} elseif ( '40004' === $response->code && 'ACQ.TRADE_NOT_EXIST' === $response->sub_code ) {
				// Trade doesn't exist - this is normal for newly created orders
				return false;
			} else {
				// Query failed
				$error_msg = isset( $response->sub_msg ) ? $response->sub_msg : $response->msg;
				$this->log( 'Order #' . $order->get_id() . ' status query failed: ' . $error_msg, 'error' );
				
				return new WP_Error( 'query_failed', $error_msg );
			}
		} catch ( Exception $e ) {
			$this->log( 'Exception when querying order #' . $order->get_id() . ': ' . $e->getMessage(), 'error' );
			return new WP_Error( 'exception', $e->getMessage() );
		}

		return false;
	}

	/**
	 * AJAX handler for querying order status
	 */
	public function ajax_query_order_status() {
		check_ajax_referer( 'woo_alipay_query_order', 'nonce' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		
		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order ID', 'woo-alipay' ) ) );
		}

		$order = wc_get_order( $order_id );
		
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found', 'woo-alipay' ) ) );
		}

		// Check if order is already paid
		if ( $order->is_paid() ) {
			wp_send_json_success( array(
				'status' => 'paid',
				'message' => __( 'Order is already paid', 'woo-alipay' ),
				'redirect' => $order->get_checkout_order_received_url(),
			) );
		}

		// Query order status
		$result = $this->query_single_order_status( $order );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		if ( true === $result ) {
			// Reload order
			$order = wc_get_order( $order_id );
			
			wp_send_json_success( array(
				'status' => $order->get_status(),
				'message' => __( 'Payment confirmed', 'woo-alipay' ),
				'redirect' => $order->get_checkout_order_received_url(),
			) );
		}

		wp_send_json_success( array(
			'status' => 'pending',
			'message' => __( 'Payment is still pending', 'woo-alipay' ),
		) );
	}

	/**
	 * Get gateway instance
	 *
	 * @return WC_Alipay|null
	 */
	private function get_gateway() {
		if ( ! $this->gateway ) {
			$gateways = WC()->payment_gateways->payment_gateways();
			$this->gateway = isset( $gateways['alipay'] ) ? $gateways['alipay'] : null;
		}
		
		return $this->gateway;
	}

	/**
	 * Get Alipay config
	 *
	 * @param int $order_id
	 * @return array
	 */
	private function get_config( $order_id = 0 ) {
		$gateway = $this->get_gateway();
		
		if ( ! $gateway || ! method_exists( $gateway, 'get_option' ) ) {
			return array();
		}

		$order = ( 0 === $order_id ) ? false : wc_get_order( $order_id );
		
		return array(
			'app_id'                 => $gateway->get_option( 'appid' ),
			'merchant_private_key'   => $gateway->get_option( 'private_key' ),
			'notify_url'             => WC()->api_request_url( 'WC_Alipay' ),
			'return_url'             => $order ? $order->get_checkout_order_received_url() : get_home_url(),
			'charset'                => 'utf-8',
			'sign_type'              => 'RSA2',
			'gatewayUrl'             => ( 'yes' === $gateway->get_option( 'sandbox' ) ) ? 'https://openapi.alipaydev.com/gateway.do' : 'https://openapi.alipay.com/gateway.do',
			'alipay_public_key'      => $gateway->get_option( 'public_key' ),
		);
	}

	/**
	 * Log message
	 *
	 * @param string $message
	 * @param string $level
	 */
	private function log( $message, $level = 'info' ) {
		$gateway = $this->get_gateway();
		
		if ( $gateway && method_exists( $gateway, 'get_option' ) ) {
			$log_enabled = ( 'yes' === $gateway->get_option( 'debug', 'no' ) );
			
			if ( $log_enabled ) {
				$logger = wc_get_logger();
				$logger->log( $level, $message, array( 'source' => 'alipay-query' ) );
			}
		}
	}
}

// Initialize the class
new WC_Alipay_Order_Query();
