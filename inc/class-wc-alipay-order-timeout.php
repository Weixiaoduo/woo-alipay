<?php
/**
 * Alipay Order Timeout Class
 * 
 * Handles automatic cancellation of unpaid orders
 * 
 * @package Woo_Alipay
 * @since 3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WC_Alipay_Order_Timeout {

	/**
	 * Default timeout duration in minutes
	 */
	const DEFAULT_TIMEOUT = 30;

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
		// Schedule cron job for checking timeout orders
		add_action( 'wp', array( $this, 'schedule_timeout_check' ) );
		add_action( 'woo_alipay_check_timeout_orders', array( $this, 'check_timeout_orders' ) );
		
		// Add timeout info to order
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'add_timeout_meta' ), 10, 3 );
	}

	/**
	 * Schedule cron job
	 */
	public function schedule_timeout_check() {
		$gateway = $this->get_gateway();
		
		// Check if feature is enabled
		if ( ! $gateway || 'yes' !== $gateway->get_option( 'enable_order_timeout', 'yes' ) ) {
			// Unschedule if disabled
			$timestamp = wp_next_scheduled( 'woo_alipay_check_timeout_orders' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'woo_alipay_check_timeout_orders' );
			}
			return;
		}
		
		if ( ! wp_next_scheduled( 'woo_alipay_check_timeout_orders' ) ) {
			// Check every 10 minutes
			wp_schedule_event( time(), 'woo_alipay_10min', 'woo_alipay_check_timeout_orders' );
		}
	}

	/**
	 * Check timeout orders
	 */
	public function check_timeout_orders() {
		$gateway = $this->get_gateway();
		
		// Check if feature is enabled
		if ( ! $gateway || 'yes' !== $gateway->get_option( 'enable_order_timeout', 'yes' ) ) {
			return;
		}
		
		$timeout = $this->get_timeout_duration();
		
		// Get pending/on-hold Alipay orders
		$args = array(
			'status'         => array( 'pending', 'on-hold' ),
			'payment_method' => 'alipay',
			'limit'          => 100,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'date_created'   => '<' . ( time() - $timeout * 60 ), // Orders older than timeout duration
		);

		$orders = wc_get_orders( $args );

		foreach ( $orders as $order ) {
			$this->cancel_timeout_order( $order );
		}
	}

	/**
	 * Cancel timeout order
	 *
	 * @param WC_Order $order
	 * @return bool
	 */
	private function cancel_timeout_order( $order ) {
		if ( ! $order || $order->is_paid() ) {
			return false;
		}

		// Check if order has timeout meta
		$timeout_time = $order->get_meta( '_alipay_timeout_time' );
		
		if ( $timeout_time && time() < $timeout_time ) {
			// Not timeout yet
			return false;
		}

		// Check if order was sent to Alipay
		$out_trade_no = $order->get_meta( 'alipay_initalRequest' );
		
		if ( empty( $out_trade_no ) ) {
			// Order wasn't sent to Alipay, safe to cancel
			$order->update_status( 
				'cancelled', 
				__( 'Order cancelled due to payment timeout (not sent to Alipay)', 'woo-alipay' ) 
			);
			
			$this->log( 'Order #' . $order->get_id() . ' cancelled due to timeout (not sent to Alipay)' );
			
			return true;
		}

		// Order was sent to Alipay, query status first before cancelling
		if ( class_exists( 'WC_Alipay_Order_Query' ) ) {
			$query = new WC_Alipay_Order_Query();
			$result = $query->query_single_order_status( $order );
			
			// Reload order after query
			$order = wc_get_order( $order->get_id() );
			
			if ( $order->is_paid() ) {
				// Payment was completed
				$this->log( 'Order #' . $order->get_id() . ' was paid, not cancelling' );
				return false;
			}
		}

		// Cancel the order
		$order->update_status( 
			'cancelled', 
			sprintf( 
				__( 'Order cancelled due to payment timeout (%d minutes)', 'woo-alipay' ), 
				$this->get_timeout_duration() 
			) 
		);
		
		$this->log( 'Order #' . $order->get_id() . ' cancelled due to timeout' );
		
		// Trigger action for other plugins
		do_action( 'woo_alipay_order_timeout_cancelled', $order );
		
		return true;
	}

	/**
	 * Add timeout meta to order
	 *
	 * @param int $order_id
	 * @param array $posted_data
	 * @param WC_Order $order
	 */
	public function add_timeout_meta( $order_id, $posted_data, $order ) {
		if ( $order->get_payment_method() !== 'alipay' ) {
			return;
		}
		
		$gateway = $this->get_gateway();
		
		// Check if feature is enabled
		if ( ! $gateway || 'yes' !== $gateway->get_option( 'enable_order_timeout', 'yes' ) ) {
			return;
		}

		$timeout = $this->get_timeout_duration();
		$timeout_time = time() + ( $timeout * 60 );
		
		$order->update_meta_data( '_alipay_timeout_time', $timeout_time );
		$order->update_meta_data( '_alipay_timeout_duration', $timeout );
		$order->save();
	}

	/**
	 * Get timeout duration from settings
	 *
	 * @return int Timeout duration in minutes
	 */
	private function get_timeout_duration() {
		$gateway = $this->get_gateway();
		
		if ( ! $gateway ) {
			return self::DEFAULT_TIMEOUT;
		}

		$timeout = $gateway->get_option( 'order_timeout', self::DEFAULT_TIMEOUT );
		
		return absint( $timeout ) > 0 ? absint( $timeout ) : self::DEFAULT_TIMEOUT;
	}


	/**
	 * Get gateway instance
	 *
	 * @return WC_Alipay|null
	 */
	private function get_gateway() {
		$gateways = WC()->payment_gateways->payment_gateways();
		return isset( $gateways['alipay'] ) ? $gateways['alipay'] : null;
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
				$logger->log( $level, $message, array( 'source' => 'alipay-timeout' ) );
			}
		}
	}
}

// Initialize the class
new WC_Alipay_Order_Timeout();
