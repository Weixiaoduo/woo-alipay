<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Woo_Alipay {

	protected static $alipay_lib_paths;

	public function __construct( $alipay_lib_paths, $init_hooks = false ) {

		self::$alipay_lib_paths = $alipay_lib_paths;
		$plugin_base_name       = plugin_basename( WOO_ALIPAY_PLUGIN_PATH );

		if ( $init_hooks ) {
			// Add translation
			add_action( 'init', array( $this, 'load_textdomain' ), 0, 0 );
			// Add main scripts & styles
			add_action( 'wp_enqueue_scripts', array( $this, 'add_frontend_scripts' ), 10, 0 );
            // Add admin scripts
            add_action( 'admin_enqueue_scripts', array( $this, 'add_admin_scripts' ), 99, 1 );
            // Admin tools
            add_action( 'admin_menu', array( $this, 'add_admin_pages' ) );
			// Add custom cron intervals
			add_filter( 'cron_schedules', array( $this, 'add_cron_intervals' ), 10, 1 );

			// Add alipay payment gateway
			add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateway' ), 10, 1 );
			// Add alipay payment gateway settings page
			add_filter( 'plugin_action_links_' . $plugin_base_name, array( $this, 'plugin_edit_link' ), 10, 1 );
			// Display alipay transction number on order page
			add_filter( 'woocommerce_get_order_item_totals', array( $this, 'display_order_meta_for_customer' ), 10, 2 );
			// Add Alipay orphan transactions email notification
			add_filter( 'woocommerce_email_classes', array( $this, 'add_orphan_transaction_woocommerce_email' ), 10, 1 );
			
			// Add WooCommerce Blocks support
			$this->woocommerce_gateway_alipay_woocommerce_block_support();

			// Enhance Payments providers response with contextual links for Alipay gateways
			add_filter( 'rest_post_dispatch', array( $this, 'filter_payments_providers_response' ), 10, 3 );
		}
	}

	/*******************************************************************
	 * Public methods
	 *******************************************************************/

	public static function activate() {
		wp_cache_flush();

		if ( ! get_option( 'woo_alipay_plugin_version' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';

			$plugin_data = get_plugin_data( WOO_ALIPAY_PLUGIN_FILE );
			$version     = $plugin_data['Version'];

			update_option( 'woo_alipay_plugin_version', $version );
		}
	}

	public static function deactivate() {}

	public static function uninstall() {
		require_once WOO_ALIPAY_PLUGIN_PATH . 'uninstall.php';
	}

	public static function require_lib( $operation_type ) {

		foreach ( self::$alipay_lib_paths[ $operation_type ] as $class_name => $path ) {

			if ( ! class_exists( $class_name ) ) {
				require_once $path;
			}
		}
	}

	public static function locate_template( $template_name, $load = false, $require_once = true ) {
		$paths    = array(
			'plugins/woo-alipay/' . $template_name,
			'woo-alipay/' . $template_name,
			'woocommerce/woo-alipay/' . $template_name,
			$template_name,
		);
		$template = locate_template(
			$paths,
			$load,
			$require_once
		);

		if ( empty( $template ) ) {
			$template = WOO_ALIPAY_PLUGIN_PATH . 'inc/templates/' . $template_name;

			if ( $load && '' !== $template ) {
				load_template( $template, $require_once );
			}
		}

		return $template;
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'woo-alipay', false, 'woo-alipay/languages' );
	}

	public function add_frontend_scripts() {
		$debug   = (bool) ( constant( 'WP_DEBUG' ) );
		$css_ext = ( $debug ) ? '.css' : '.min.css';
		$version = filemtime( WOO_ALIPAY_PLUGIN_PATH . 'css/main' . $css_ext );

		wp_enqueue_style( 'woo-alipay-main-style', WOO_ALIPAY_PLUGIN_URL . 'css/main.css', array(), $version );
		
		// Enqueue payment polling script on order-pay page
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			// Get gateway settings
			$gateways = WC()->payment_gateways->payment_gateways();
			$gateway = isset( $gateways['alipay'] ) ? $gateways['alipay'] : null;
			
			// Check if polling is enabled
			if ( $gateway && 'yes' === $gateway->get_option( 'enable_payment_polling', 'yes' ) ) {
				$script_version = file_exists( WOO_ALIPAY_PLUGIN_PATH . 'js/payment-polling.js' ) 
					? filemtime( WOO_ALIPAY_PLUGIN_PATH . 'js/payment-polling.js' ) 
					: '3.3.0';
				
				wp_enqueue_script(
					'woo-alipay-payment-polling',
					WOO_ALIPAY_PLUGIN_URL . 'js/payment-polling.js',
					array( 'jquery' ),
					$script_version,
					true
				);
				
				// Get settings
				$polling_interval = absint( $gateway->get_option( 'polling_interval', '3' ) ) * 1000;
				$max_attempts = absint( $gateway->get_option( 'polling_max_attempts', '60' ) );
				
				// Localize script
				wp_localize_script(
					'woo-alipay-payment-polling',
					'woo_alipay_polling',
					array(
						'ajax_url'      => admin_url( 'admin-ajax.php' ),
						'nonce'         => wp_create_nonce( 'woo_alipay_query_order' ),
						'poll_interval' => $polling_interval,
						'max_attempts'  => $max_attempts,
						'strings'       => array(
							'checking'     => __( '检查中...', 'woo-alipay' ),
							'check_status' => __( '检查支付状态', 'woo-alipay' ),
							'timeout'      => __( '支付状态查询超时，请手动刷新页面。', 'woo-alipay' ),
							'error'        => __( '查询失败，请稍后再试。', 'woo-alipay' ),
						),
					)
				);
			}
		}
	}

    public function add_admin_scripts( $hook ) {

        if ( 'woocommerce_page_wc-settings' !== $hook ) {
            return;
        }

        // Only load our admin assets on our gateway settings sections, not on the Payments list view.
        $tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
        $section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
        $our_sections = array( 'alipay', 'alipay_installment', 'alipay_facetopay' );
        if ( 'checkout' !== $tab || ! in_array( $section, $our_sections, true ) ) {
            return;
        }

        $debug       = (bool) ( constant( 'WP_DEBUG' ) );
        $css_ext     = ( $debug ) ? '.css' : '.min.css';
        $js_ext      = ( $debug ) ? '.js' : '.min.js';
        $version_css = filemtime( WOO_ALIPAY_PLUGIN_PATH . 'css/admin/main' . $css_ext );
        $version_js  = filemtime( WOO_ALIPAY_PLUGIN_PATH . 'js/admin/main' . $js_ext );

        wp_enqueue_style(
            'woo-alipay-main-style',
            WOO_ALIPAY_PLUGIN_URL . 'css/admin/main' . $css_ext,
            array(),
            $version_css
        );

        $parameters = array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'debug'    => $debug,
        );

        wp_enqueue_script(
            'woo-alipay-admin-script',
            WOO_ALIPAY_PLUGIN_URL . 'js/admin/main' . $js_ext,
            array( 'jquery' ),
            $version_js,
            true
        );
        wp_localize_script( 'woo-alipay-admin-script', 'WooAlipay', $parameters );
    }

    public function add_admin_pages() {
        // If the dedicated Reconcile Pro extension is active, avoid adding a duplicate menu.
        if ( class_exists( 'Woo_Alipay_Reconcile_Admin' ) ) {
            return;
        }
        add_submenu_page(
            'woocommerce',
            __( '支付宝对账工具', 'woo-alipay' ),
            __( '支付宝对账', 'woo-alipay' ),
            'manage_woocommerce',
            'woo-alipay-reconcile',
            array( $this, 'render_admin_reconcile_page' )
        );
    }

    /**
     * Inject provider links into the WooCommerce Payments providers REST response.
     */
    public function filter_payments_providers_response( $response, $server, $request ) {
        try {
            // Allow site owners to disable this injection for troubleshooting.
            if ( false === apply_filters( 'woo_alipay_enable_payments_links_injection', true ) ) {
                return $response;
            }

            if ( ! $response || ! is_a( $response, 'WP_REST_Response' ) ) {
                return $response;
            }

            $route  = is_object( $request ) && method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
            $method = is_object( $request ) && method_exists( $request, 'get_method' ) ? (string) $request->get_method() : '';

            // Only act on the Payments providers endpoint used by the settings page (POST /wc-admin/settings/payments/providers).
            if ( false === strpos( $route, '/wc-admin/settings/payments/providers' ) || 'POST' !== strtoupper( $method ) ) {
                return $response;
            }

            $data = $response->get_data();
            if ( ! is_array( $data ) || empty( $data['providers'] ) || ! is_array( $data['providers'] ) ) {
                return $response;
            }

            $pricing_url = apply_filters( 'woo_alipay_pricing_url', 'https://woocn.com/product/woo-alipay.html#pricing' );
            $about_url   = apply_filters( 'woo_alipay_learn_more_url', 'https://woocn.com/document/woo-alipay' );
            $terms_url   = apply_filters( 'woo_alipay_terms_url', 'https://woocn.com/terms' );
            $docs_url    = apply_filters( 'woo_alipay_docs_url', 'https://woocn.com/document/woo-alipay' );
            $support_url = apply_filters( 'woo_alipay_support_url', 'https://woocn.com/support' );

            $target_ids = array( 'alipay', 'alipay_installment', 'alipay_facetopay' );

            foreach ( $data['providers'] as $idx => $provider ) {
                if ( ! is_array( $provider ) || empty( $provider['id'] ) || ! in_array( $provider['id'], $target_ids, true ) ) {
                    continue;
                }

                $links = array();
                if ( isset( $provider['links'] ) && is_array( $provider['links'] ) ) {
                    $links = $provider['links'];
                }

                // Track existing link types to avoid duplicates.
                $existing_types = array();
                foreach ( $links as $link ) {
                    if ( is_array( $link ) && ! empty( $link['_type'] ) ) {
                        $existing_types[] = $link['_type'];
                    }
                }

                $to_add = array(
                    array( '_type' => 'pricing',        'url' => esc_url_raw( $pricing_url ) ),
                    array( '_type' => 'about',          'url' => esc_url_raw( $about_url ) ),
                    array( '_type' => 'terms',          'url' => esc_url_raw( $terms_url ) ),
                    array( '_type' => 'documentation',  'url' => esc_url_raw( $docs_url ) ),
                    array( '_type' => 'support',        'url' => esc_url_raw( $support_url ) ),
                );

                foreach ( $to_add as $entry ) {
                    if ( empty( $entry['_type'] ) || empty( $entry['url'] ) ) {
                        continue;
                    }
                    if ( in_array( $entry['_type'], $existing_types, true ) ) {
                        continue;
                    }
                    $links[] = $entry;
                }

                $data['providers'][ $idx ]['links'] = $links;
            }

            $response->set_data( $data );
        } catch ( \Throwable $e ) {
            // Fail-safe: do not block the response.
        }

        return $response;
    }

    public function render_admin_reconcile_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( __( '权限不足', 'woo-alipay' ) );
        }
        $result = null; $error = '';
        if ( isset( $_POST['woo_alipay_reconcile_nonce'] ) && wp_verify_nonce( $_POST['woo_alipay_reconcile_nonce'], 'woo_alipay_reconcile' ) ) {
            $out_trade_no = sanitize_text_field( $_POST['out_trade_no'] ?? '' );
            $trade_no = sanitize_text_field( $_POST['trade_no'] ?? '' );
            require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
            $main_gateway = new WC_Alipay(false);
            $config = Alipay_SDK_Helper::get_alipay_config(array(
                'appid' => $main_gateway->get_option('appid'),
                'private_key' => $main_gateway->get_option('private_key'),
                'public_key' => $main_gateway->get_option('public_key'),
                'sandbox' => $main_gateway->get_option('sandbox'),
            ));
            $query = Alipay_SDK_Helper::query_order( $out_trade_no, $trade_no, $config );
            if ( is_wp_error( $query ) ) {
                $error = $query->get_error_message();
            } else {
                $result = $query;
                // 可选：尝试同步 Woo 订单状态
                if ( isset($_POST['sync_order']) && $result['success'] && in_array( $result['trade_status'], array('TRADE_SUCCESS','TRADE_FINISHED'), true ) ) {
                    $orders = wc_get_orders( array( 'meta_key' => '_alipay_out_trade_no', 'meta_value' => $result['out_trade_no'], 'limit' => 1 ) );
                    if ( ! empty( $orders ) ) {
                        $order = $orders[0];
                        if ( ! $order->is_paid() ) {
                            $order->payment_complete( $result['trade_no'] );
                            $order->add_order_note( sprintf( __( '对账同步：标记为已支付，交易号: %s', 'woo-alipay' ), $result['trade_no'] ) );
                        }
                    }
                }
            }
        }
        echo '<div class="wrap"><h1>' . esc_html__( '支付宝对账工具', 'woo-alipay' ) . '</h1>';
        echo '<form method="post">';
        wp_nonce_field( 'woo_alipay_reconcile', 'woo_alipay_reconcile_nonce' );
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="out_trade_no">' . esc_html__( '商户订单号(out_trade_no)', 'woo-alipay' ) . '</label></th><td><input type="text" name="out_trade_no" id="out_trade_no" class="regular-text" /></td></tr>';
        echo '<tr><th><label for="trade_no">' . esc_html__( '支付宝交易号(trade_no)', 'woo-alipay' ) . '</label></th><td><input type="text" name="trade_no" id="trade_no" class="regular-text" /></td></tr>';
        echo '</tbody></table>';
        submit_button( __( '查询', 'woo-alipay' ) );
        if ( $result ) {
            echo '<h2>' . esc_html__( '查询结果', 'woo-alipay' ) . '</h2>';
            echo '<pre>' . esc_html( print_r( $result, true ) ) . '</pre>';
            echo '<p><label><input type="checkbox" name="sync_order" value="1" /> ' . esc_html__( '若支付成功，尝试同步 Woo 订单为已支付', 'woo-alipay' ) . '</label></p>';
            submit_button( __( '同步订单状态', 'woo-alipay' ) );
        }
        if ( $error ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
        }
        echo '</form></div>';
    }

	public function add_gateway( $methods ) {
		$methods[] = 'WC_Alipay';

		// Extension gateways are registered by their own plugins now.
		return $methods;
	}

	public function plugin_edit_link( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=alipay' );

		return array_merge(
			array(
				'settings' => '<a href="' . $url . '">' . __( 'Settings', 'woo-alipay' ) . '</a>',
			),
			$links
		);
	}

	public function display_order_meta_for_customer( $total_rows, $order ) {
		$trade_no = $order->get_transaction_id();

		if ( ! empty( $trade_no ) && $order->get_payment_method() === 'alipay' ) {
			$new_row = array(
				'alipay_trade_no' => array(
					'label' => __( 'Transaction:', 'woo-alipay' ),
					'value' => $trade_no,
				),
			);

			$total_rows = array_merge( array_splice( $total_rows, 0, 2 ), $new_row, $total_rows );
        }

        // 花呗分期：展示期数与免息
        if ( $order->get_payment_method() === 'alipay_installment' ) {
            $period = $order->get_meta('_alipay_installment_period');
            if ( $period ) {
                $label = sprintf( __( '分期：%s期', 'woo-alipay' ), esc_html( $period ) );
                // 判断是否免息
                $fee_note = '';
                $installment_gateway = null;
                if ( function_exists('WC') && isset( WC()->payment_gateways ) ) {
                    $gateways = WC()->payment_gateways->payment_gateways();
                    $installment_gateway = isset($gateways['alipay_installment']) ? $gateways['alipay_installment'] : null;
                }
                if ( $installment_gateway && 'seller' === $installment_gateway->get_option('fee_bearer', 'user') ) {
                    $fee_note = '（' . __( '免息', 'woo-alipay' ) . '）';
                }
                $new_row = array(
                    'alipay_installment' => array(
                        'label' => __( '分期：', 'woo-alipay' ),
                        'value' => esc_html( $period ) . $fee_note,
                    ),
                );
                $total_rows = array_merge( $total_rows, $new_row );
            }
        }

        return $total_rows;
	}

	public function pay_notification_endpoint( $endpoint ) {

		return 'wc-api/WC_Alipay/';
	}

	public function add_orphan_transaction_woocommerce_email( $email_classes ) {
		require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-wc-email-alipay-orphan-transaction.php';

		$email_classes['WC_Email_Alipay_Orphan_Transaction'] = new WC_Email_Alipay_Orphan_Transaction();

		return $email_classes;
	}

	public function woocommerce_gateway_alipay_woocommerce_block_support() {
		// 始终挂载注册回调；在 Blocks 触发该钩子时再加载依赖并注册方法
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
				// 到这里时，Blocks 已加载，父类可用，安全加载支持类
				if ( file_exists( WOO_ALIPAY_PLUGIN_PATH . 'inc/class-wc-alipay-blocks-support.php' ) ) {
					require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-wc-alipay-blocks-support.php';
				}


				// 注册主支付宝网关（扩展的 Blocks 支持由扩展自身注册）
				if ( class_exists( 'WC_Alipay_Blocks_Support' ) ) {
					$payment_method_registry->register( new WC_Alipay_Blocks_Support() );
				}
			}
		);
	}

	/**
	 * Add custom cron intervals
	 *
	 * @param array $schedules
	 * @return array
	 */
	public function add_cron_intervals( $schedules ) {
		// Add 5 minute interval
		if ( ! isset( $schedules['woo_alipay_5min'] ) ) {
			$schedules['woo_alipay_5min'] = array(
				'interval' => 300,
				'display'  => __( 'Every 5 Minutes', 'woo-alipay' ),
			);
		}
		
		// Add 10 minute interval
		if ( ! isset( $schedules['woo_alipay_10min'] ) ) {
			$schedules['woo_alipay_10min'] = array(
				'interval' => 600,
				'display'  => __( 'Every 10 Minutes', 'woo-alipay' ),
			);
		}
		
		// Add 15 minute interval
		if ( ! isset( $schedules['woo_alipay_15min'] ) ) {
			$schedules['woo_alipay_15min'] = array(
				'interval' => 900,
				'display'  => __( 'Every 15 Minutes', 'woo-alipay' ),
			);
		}
		
		// Add 30 minute interval
		if ( ! isset( $schedules['woo_alipay_30min'] ) ) {
			$schedules['woo_alipay_30min'] = array(
				'interval' => 1800,
				'display'  => __( 'Every 30 Minutes', 'woo-alipay' ),
			);
		}
		
		return $schedules;
	}
}
