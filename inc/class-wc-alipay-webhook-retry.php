<?php
/**
 * Alipay Webhook Retry Class
 * 
 * Handles recording and retrying failed Alipay notifications
 * 
 * @package Woo_Alipay
 * @since 3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WC_Alipay_Webhook_Retry {

	/**
	 * Table name for webhook logs
	 */
	const TABLE_NAME = 'woo_alipay_webhook_logs';

	/**
	 * Maximum retry attempts (default)
	 */
	const MAX_RETRIES = 5;
	
	/**
	 * Gateway instance
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
		// Create table on plugin activation
		register_activation_hook( WOO_ALIPAY_PLUGIN_FILE, array( $this, 'create_table' ) );
		
		// Log webhook attempts
		add_action( 'woocommerce_api_wc_alipay', array( $this, 'log_webhook_attempt' ), 5 );
		
		// Schedule retry cron
		add_action( 'wp', array( $this, 'schedule_retry_cron' ) );
		add_action( 'woo_alipay_retry_failed_webhooks', array( $this, 'retry_failed_webhooks' ) );
		
		// Add admin menu for webhook logs
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 60 );
		
		// AJAX handler for manual retry
		add_action( 'wp_ajax_woo_alipay_retry_webhook', array( $this, 'ajax_retry_webhook' ) );
	}

	/**
	 * Create webhook logs table
	 */
	public function create_table() {
		global $wpdb;
		
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			order_id bigint(20) NOT NULL,
			out_trade_no varchar(64) NOT NULL,
			trade_no varchar(64) DEFAULT NULL,
			request_data longtext NOT NULL,
			response_data text DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			retry_count int(11) NOT NULL DEFAULT 0,
			last_error text DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY order_id (order_id),
			KEY out_trade_no (out_trade_no),
			KEY status (status),
			KEY created_at (created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Log webhook attempt
	 */
	public function log_webhook_attempt() {
		global $wpdb;
		
		// Check if feature is enabled
		$gateway = $this->get_gateway();
		if ( ! $gateway || 'yes' !== $gateway->get_option( 'enable_webhook_retry', 'yes' ) ) {
			return;
		}
		
		// Only log POST requests with data
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || empty( $_POST ) ) {
			return;
		}

		$request_data = $_POST;
		$out_trade_no = isset( $request_data['out_trade_no'] ) ? sanitize_text_field( $request_data['out_trade_no'] ) : '';
		$trade_no = isset( $request_data['trade_no'] ) ? sanitize_text_field( $request_data['trade_no'] ) : '';
		
		if ( empty( $out_trade_no ) ) {
			return;
		}

		// Extract order ID
		$out_trade_no_parts = explode( '-', str_replace( 'WooA', '', $out_trade_no ) );
		$order_id = absint( array_shift( $out_trade_no_parts ) );

		// Check if this webhook was already logged recently
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $table_name 
			WHERE out_trade_no = %s 
			AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
			ORDER BY id DESC LIMIT 1",
			$out_trade_no
		) );

		$now = current_time( 'mysql' );

		if ( $existing ) {
			// Update existing log
			$wpdb->update(
				$table_name,
				array(
					'request_data' => wp_json_encode( $request_data ),
					'trade_no'     => $trade_no,
					'updated_at'   => $now,
				),
				array( 'id' => $existing ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			// Insert new log
			$wpdb->insert(
				$table_name,
				array(
					'order_id'      => $order_id,
					'out_trade_no'  => $out_trade_no,
					'trade_no'      => $trade_no,
					'request_data'  => wp_json_encode( $request_data ),
					'status'        => 'pending',
					'retry_count'   => 0,
					'created_at'    => $now,
					'updated_at'    => $now,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Update webhook log status
	 *
	 * @param string $out_trade_no
	 * @param string $status
	 * @param string $error
	 */
	public function update_webhook_status( $out_trade_no, $status, $error = '' ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$now = current_time( 'mysql' );

		$data = array(
			'status'     => $status,
			'updated_at' => $now,
		);
		$format = array( '%s', '%s' );

		if ( ! empty( $error ) ) {
			$data['last_error'] = $error;
			$format[] = '%s';
		}

		if ( 'failed' === $status ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE $table_name SET retry_count = retry_count + 1 WHERE out_trade_no = %s",
				$out_trade_no
			) );
		}

		$wpdb->update(
			$table_name,
			$data,
			array( 'out_trade_no' => $out_trade_no ),
			$format,
			array( '%s' )
		);
	}

	/**
	 * Schedule retry cron
	 */
	public function schedule_retry_cron() {
		$gateway = $this->get_gateway();
		
		// Check if feature is enabled
		if ( ! $gateway || 'yes' !== $gateway->get_option( 'enable_webhook_retry', 'yes' ) ) {
			// Unschedule if disabled
			$timestamp = wp_next_scheduled( 'woo_alipay_retry_failed_webhooks' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'woo_alipay_retry_failed_webhooks' );
			}
			return;
		}
		
		if ( ! wp_next_scheduled( 'woo_alipay_retry_failed_webhooks' ) ) {
			// Retry every hour
			wp_schedule_event( time(), 'hourly', 'woo_alipay_retry_failed_webhooks' );
		}
	}

	/**
	 * Retry failed webhooks
	 */
	public function retry_failed_webhooks() {
		global $wpdb;
		
		// Check if feature is enabled
		$gateway = $this->get_gateway();
		if ( ! $gateway || 'yes' !== $gateway->get_option( 'enable_webhook_retry', 'yes' ) ) {
			return;
		}
		
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		
		// Get max retries from settings
		$max_retries = absint( $gateway->get_option( 'webhook_max_retries', self::MAX_RETRIES ) );
		$retry_interval = absint( $gateway->get_option( 'webhook_retry_interval', '10' ) );
		
		// Get failed webhooks that haven't exceeded max retries
		$failed_webhooks = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table_name 
			WHERE status = 'failed' 
			AND retry_count < %d 
			AND updated_at < DATE_SUB(NOW(), INTERVAL %d MINUTE)
			ORDER BY created_at ASC
			LIMIT 10",
			$max_retries,
			$retry_interval
		) );

		foreach ( $failed_webhooks as $webhook ) {
			$this->process_webhook_retry( $webhook );
			
			// Sleep to avoid rate limiting
			sleep( 2 );
		}
	}

	/**
	 * Process webhook retry
	 *
	 * @param object $webhook
	 * @return bool
	 */
	private function process_webhook_retry( $webhook ) {
		$order = wc_get_order( $webhook->order_id );
		
		if ( ! $order ) {
			$this->update_webhook_status( $webhook->out_trade_no, 'failed', 'Order not found' );
			return false;
		}

		// If order is already paid, mark as success
		if ( $order->is_paid() ) {
			$this->update_webhook_status( $webhook->out_trade_no, 'success', '' );
			return true;
		}

		// Query order status from Alipay
		if ( class_exists( 'WC_Alipay_Order_Query' ) ) {
			$query = new WC_Alipay_Order_Query();
			$result = $query->query_single_order_status( $order );
			
			if ( true === $result ) {
				$this->update_webhook_status( $webhook->out_trade_no, 'success', '' );
				$this->log( 'Webhook retry successful for order #' . $webhook->order_id );
				return true;
			} elseif ( is_wp_error( $result ) ) {
				$this->update_webhook_status( $webhook->out_trade_no, 'failed', $result->get_error_message() );
				$this->log( 'Webhook retry failed for order #' . $webhook->order_id . ': ' . $result->get_error_message(), 'error' );
				return false;
			}
		}

		$this->update_webhook_status( $webhook->out_trade_no, 'failed', 'Unable to confirm payment status' );
		return false;
	}

	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		// Check if feature is enabled
		$gateway = $this->get_gateway();
		if ( ! $gateway || 'yes' !== $gateway->get_option( 'enable_webhook_retry', 'yes' ) ) {
			return;
		}
		
		add_submenu_page(
			'woocommerce',
			__( 'Alipay Webhook Logs', 'woo-alipay' ),
			__( 'Alipay Webhooks', 'woo-alipay' ),
			'manage_woocommerce',
			'woo-alipay-webhooks',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render admin page
	 */
	public function render_admin_page() {
		global $wpdb;
		
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		
		// Handle bulk actions
		if ( isset( $_POST['action'] ) && 'retry_selected' === $_POST['action'] && ! empty( $_POST['webhook_ids'] ) ) {
			check_admin_referer( 'bulk-webhooks' );
			
			foreach ( $_POST['webhook_ids'] as $webhook_id ) {
				$webhook = $wpdb->get_row( $wpdb->prepare(
					"SELECT * FROM $table_name WHERE id = %d",
					absint( $webhook_id )
				) );
				
				if ( $webhook ) {
					$this->process_webhook_retry( $webhook );
				}
			}
			
			echo '<div class="notice notice-success"><p>' . __( 'Selected webhooks have been retried.', 'woo-alipay' ) . '</p></div>';
		}

		// Get statistics
		$total = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
		$pending = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE status = 'pending'" );
		$success = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE status = 'success'" );
		$failed = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE status = 'failed'" );

		// Get recent webhooks
		$current_status = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : 'all';
		$per_page = 20;
		$paged = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$offset = ( $paged - 1 ) * $per_page;

		$where = '';
		if ( 'all' !== $current_status ) {
			$where = $wpdb->prepare( "WHERE status = %s", $current_status );
		}

		$webhooks = $wpdb->get_results( 
			"SELECT * FROM $table_name $where ORDER BY created_at DESC LIMIT $per_page OFFSET $offset"
		);

		$total_items = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name $where" );
		$total_pages = ceil( $total_items / $per_page );

		?>
		<div class="wrap">
			<h1><?php _e( 'Alipay Webhook Logs', 'woo-alipay' ); ?></h1>
			
			<ul class="subsubsub">
				<li><a href="?page=woo-alipay-webhooks&status=all" <?php echo 'all' === $current_status ? 'class="current"' : ''; ?>><?php printf( __( 'All (%d)', 'woo-alipay' ), $total ); ?></a> |</li>
				<li><a href="?page=woo-alipay-webhooks&status=pending" <?php echo 'pending' === $current_status ? 'class="current"' : ''; ?>><?php printf( __( 'Pending (%d)', 'woo-alipay' ), $pending ); ?></a> |</li>
				<li><a href="?page=woo-alipay-webhooks&status=success" <?php echo 'success' === $current_status ? 'class="current"' : ''; ?>><?php printf( __( 'Success (%d)', 'woo-alipay' ), $success ); ?></a> |</li>
				<li><a href="?page=woo-alipay-webhooks&status=failed" <?php echo 'failed' === $current_status ? 'class="current"' : ''; ?>><?php printf( __( 'Failed (%d)', 'woo-alipay' ), $failed ); ?></a></li>
			</ul>

			<form method="post">
				<?php wp_nonce_field( 'bulk-webhooks' ); ?>
				<input type="hidden" name="action" value="retry_selected" />
				
				<div class="tablenav top">
					<div class="alignleft actions">
						<input type="submit" class="button action" value="<?php _e( 'Retry Selected', 'woo-alipay' ); ?>" />
					</div>
				</div>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<td class="check-column"><input type="checkbox" /></td>
							<th><?php _e( 'Order', 'woo-alipay' ); ?></th>
							<th><?php _e( 'Trade No', 'woo-alipay' ); ?></th>
							<th><?php _e( 'Status', 'woo-alipay' ); ?></th>
							<th><?php _e( 'Retry Count', 'woo-alipay' ); ?></th>
							<th><?php _e( 'Error', 'woo-alipay' ); ?></th>
							<th><?php _e( 'Created', 'woo-alipay' ); ?></th>
							<th><?php _e( 'Actions', 'woo-alipay' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $webhooks ) ) : ?>
							<tr><td colspan="8"><?php _e( 'No webhook logs found.', 'woo-alipay' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $webhooks as $webhook ) : ?>
								<tr>
									<th class="check-column">
										<input type="checkbox" name="webhook_ids[]" value="<?php echo esc_attr( $webhook->id ); ?>" />
									</th>
									<td>
										<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $webhook->order_id . '&action=edit' ) ); ?>">
											#<?php echo esc_html( $webhook->order_id ); ?>
										</a>
									</td>
									<td><?php echo esc_html( $webhook->trade_no ? $webhook->trade_no : '-' ); ?></td>
									<td>
										<span class="status-<?php echo esc_attr( $webhook->status ); ?>">
											<?php echo esc_html( ucfirst( $webhook->status ) ); ?>
										</span>
									</td>
									<td><?php echo esc_html( $webhook->retry_count ); ?> / <?php echo self::MAX_RETRIES; ?></td>
									<td><?php echo esc_html( $webhook->last_error ? substr( $webhook->last_error, 0, 50 ) . '...' : '-' ); ?></td>
									<td><?php echo esc_html( $webhook->created_at ); ?></td>
									<td>
										<?php if ( 'failed' === $webhook->status && $webhook->retry_count < self::MAX_RETRIES ) : ?>
											<button type="button" class="button button-small woo-alipay-retry-webhook" data-webhook-id="<?php echo esc_attr( $webhook->id ); ?>">
												<?php _e( 'Retry', 'woo-alipay' ); ?>
											</button>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="tablenav bottom">
						<div class="tablenav-pages">
							<?php
							echo paginate_links( array(
								'base'    => add_query_arg( 'paged', '%#%' ),
								'format'  => '',
								'current' => $paged,
								'total'   => $total_pages,
							) );
							?>
						</div>
					</div>
				<?php endif; ?>
			</form>
		</div>

		<script>
		jQuery(document).ready(function($) {
			$('.woo-alipay-retry-webhook').on('click', function() {
				var $btn = $(this);
				var webhookId = $btn.data('webhook-id');
				
				$btn.prop('disabled', true).text('<?php _e( 'Retrying...', 'woo-alipay' ); ?>');
				
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'woo_alipay_retry_webhook',
						webhook_id: webhookId,
						nonce: '<?php echo wp_create_nonce( 'woo_alipay_retry_webhook' ); ?>'
					},
					success: function(response) {
						if (response.success) {
							location.reload();
						} else {
							alert(response.data.message);
							$btn.prop('disabled', false).text('<?php _e( 'Retry', 'woo-alipay' ); ?>');
						}
					}
				});
			});
		});
		</script>

		<style>
		.status-pending { color: #f0ad4e; }
		.status-success { color: #5cb85c; }
		.status-failed { color: #d9534f; }
		</style>
		<?php
	}

	/**
	 * AJAX handler for manual retry
	 */
	public function ajax_retry_webhook() {
		check_ajax_referer( 'woo_alipay_retry_webhook', 'nonce' );
		
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'woo-alipay' ) ) );
		}

		$webhook_id = isset( $_POST['webhook_id'] ) ? absint( $_POST['webhook_id'] ) : 0;
		
		if ( ! $webhook_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid webhook ID', 'woo-alipay' ) ) );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		
		$webhook = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM $table_name WHERE id = %d",
			$webhook_id
		) );

		if ( ! $webhook ) {
			wp_send_json_error( array( 'message' => __( 'Webhook not found', 'woo-alipay' ) ) );
		}

		$result = $this->process_webhook_retry( $webhook );

		if ( $result ) {
			wp_send_json_success( array( 'message' => __( 'Webhook retried successfully', 'woo-alipay' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Webhook retry failed', 'woo-alipay' ) ) );
		}
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
	 * Log message
	 *
	 * @param string $message
	 * @param string $level
	 */
	private function log( $message, $level = 'info' ) {
		$logger = wc_get_logger();
		$logger->log( $level, $message, array( 'source' => 'alipay-webhook' ) );
	}
}

// Initialize the class
new WC_Alipay_Webhook_Retry();
