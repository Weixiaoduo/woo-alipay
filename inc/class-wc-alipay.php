<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WC_Alipay extends WC_Payment_Gateway
{

    const GATEWAY_URL = 'https://openapi.alipay.com/gateway.do';
    const GATEWAY_SANDBOX_URL = 'https://openapi.alipaydev.com/gateway.do';
    const GATEWAY_ID = 'alipay';

    protected static $log_enabled = false;
    protected static $log = false;
    protected static $refund_id;

    protected $current_currency;
    protected $multi_currency_enabled;
    protected $supported_currencies;
    protected $charset;
    protected $pay_notify_result;
    protected $refundable_status;
    protected $is_pay_handler = false;
    
    // 添加缺失的属性声明以修复 PHP 8.2+ 的 deprecated warnings
    protected $exchange_rate;
    protected $order_title_format;
    protected $order_prefix;
    protected $form_submission_method;
    protected $notify_url;

    public function __construct($init_hooks = false)
    {
        $active_plugins = apply_filters('active_plugins', get_option('active_plugins'));
        $is_wpml = in_array('woocommerce-multilingual/wpml-woocommerce.php', $active_plugins, true);
        $multi_currency_options = 'yes' === get_option('icl_enable_multi_currency');

        /* translators: In Chinese, can simply be translated as 支付宝. Mentionning "China" in English and other languages to make it clear to international customers this is NOT a crossborder payment method. */
        $this->title = __('Alipay China', 'woo-alipay');
        $this->method_title = __('Alipay by Woo Alipay', 'woo-alipay');
        $this->charset = strtolower(get_bloginfo('charset'));
        $this->id = self::GATEWAY_ID;
        $this->icon = WOO_ALIPAY_PLUGIN_URL . 'assets/images/alipay-icon.svg';
        $this->description = $this->get_option('description');
        $this->method_description = __('Alipay is a simple, secure and fast online payment method.', 'woo-alipay');
        $this->exchange_rate = $this->get_option('exchange_rate');
        $this->current_currency = get_option('woocommerce_currency');
        $this->multi_currency_enabled = $is_wpml && $multi_currency_options;
        $this->supported_currencies = array('RMB', 'CNY');
        $this->order_button_text = __('Pay with Alipay', 'woo-alipay');
        $this->order_title_format = $this->get_option('order_title_format');
        $this->order_prefix = $this->get_option('order_prefix');
        $this->has_fields = false;
        $this->form_submission_method = ('yes' === $this->get_option('form_submission_method'));
        $this->notify_url = WC()->api_request_url('WC_Alipay');
        $this->supports = array(
            'products',
            'refunds',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'multiple_subscriptions',
            'tokenization',
        );

        self::$log_enabled = ('yes' === $this->get_option('debug', 'no'));

        if (!in_array($this->charset, array('gbk', 'utf-8'), true)) {
            $this->charset = 'utf-8';
        }

        $this->setup_form_fields();
        $this->init_settings();


        // Register subscription scheduled payment hook
        if ( class_exists('WC_Subscriptions') || function_exists('wcs_order_contains_subscription') || function_exists('wcs_is_subscription') ) {
            add_action('woocommerce_scheduled_subscription_payment_' . $this->id, array($this, 'scheduled_subscription_payment'), 10, 2);
        }

        if ($init_hooks) {
            // Add save gateway options callback
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
                $this,
                'process_admin_options'
            ), 10, 0);
            // Add test connexion ajax callback
            add_action('wp_ajax_woo_alipay_test_connection', array($this, 'test_connection'), 10, 0);

            if ($this->is_wooalipay_enabled()) {
                $this->description = $this->title;

                // Check alipay response to see if payment is complete
                add_action('woocommerce_api_wc_alipay', array($this, 'check_alipay_response'), 10, 0);
                // Remember the refund info at creation for later use
                add_action('woocommerce_create_refund', array($this, 'remember_refund_info'), 10, 2);
                // Put the order on hol and add redirection form on receipt page
                add_action('woocommerce_receipt_alipay', array($this, 'receipt_page'), 10, 1);

                // Stricter user sanitation
                add_filter('sanitize_user', array($this, 'sanitize_user_strict'), 10, 3);
            }

            $this->validate_settings();
        }
    }

        /**
     * Woo Subscriptions: scheduled payment callback
     *
     * @param float      $amount_to_charge
     * @param WC_Order   $renewal_order
     */
    public function scheduled_subscription_payment( $amount_to_charge, $renewal_order )
    {
        try {
            if ( 'yes' !== $this->get_option( 'enable_auto_renew', 'no' ) ) {
                $renewal_order->add_order_note( __( '未启用自动续费，跳过代扣。', 'woo-alipay' ) );
                $renewal_order->update_status( 'failed', __( '自动续费未启用', 'woo-alipay' ) );
                return;
            }

            $user_id = $renewal_order->get_user_id();
            if ( ! $user_id || ! class_exists( 'WC_Alipay_Agreement' ) ) {
                $renewal_order->update_status( 'failed', __( '找不到用户或协议管理不可用。', 'woo-alipay' ) );
                return;
            }

            $agreement_no = WC_Alipay_Agreement::get_user_agreement_no( $user_id );
            if ( ! $agreement_no ) {
                $renewal_order->update_status( 'failed', __( '未查询到支付宝扣款协议，请先完成签约。', 'woo-alipay' ) );
                return;
            }

            require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradeCreateRequest.php';

            $config = Alipay_SDK_Helper::get_alipay_config( array(
                'appid'       => $this->get_option('appid'),
                'private_key' => $this->get_option('private_key'),
                'public_key'  => $this->get_option('public_key'),
                'sandbox'     => $this->get_option('sandbox'),
            ) );

            $aop = Alipay_SDK_Helper::create_alipay_service( $config );
            if ( ! $aop ) {
                $renewal_order->update_status( 'failed', __( '创建支付宝服务失败（自动续费）。', 'woo-alipay' ) );
                return;
            }

            $out_trade_no = 'SubR' . $renewal_order->get_id() . '-' . current_time('timestamp');
            // 记录到订单元数据，便于回调查询定位
            $renewal_order->update_meta_data( '_alipay_out_trade_no', $out_trade_no );
            $renewal_order->save();

            $total = $this->maybe_convert_amount( $amount_to_charge );
            $subject = $this->get_option( 'auto_renew_subject_prefix', __( '订阅续费', 'woo-alipay' ) ) . ' - #' . $renewal_order->get_id();

            $product_code = apply_filters( 'woo_alipay_agreement_trade_product_code', $this->get_option( 'agreement_product_code', 'CYCLE_PAY_AUTH' ), $renewal_order );
            $biz = array(
                'out_trade_no'   => $out_trade_no,
                'total_amount'   => $total,
                'subject'        => $subject,
                'product_code'   => $product_code,
                'agreement_params' => array(
                    'agreement_no' => $agreement_no,
                ),
            );

            $biz = apply_filters( 'woo_alipay_agreement_trade_biz_content', $biz, $renewal_order, $agreement_no );

            $request = new AlipayTradeCreateRequest();
            $request->setBizContent( wp_json_encode( $biz ) );
            $request->setNotifyUrl( apply_filters( 'woo_alipay_gateway_notify_url', $this->notify_url, $renewal_order->get_id() ) );

            $response = $aop->execute( $request );
            $node = 'alipay_trade_create_response';
            $result = $response->$node ?? null;

            if ( ! $result || ! isset( $result->code ) || '10000' !== $result->code ) {
                self::log( __METHOD__ . ' TradeCreate error: ' . wc_print_r( $response, true ), 'error' );
                $renewal_order->update_status( 'failed', __( '支付宝代扣下单失败。', 'woo-alipay' ) );
                return;
            }

            // 可选：立即查询一次状态
            $query = Alipay_SDK_Helper::query_order( $out_trade_no, '', $config );
            if ( ! is_wp_error( $query ) && ! empty( $query['trade_status'] ) && in_array( $query['trade_status'], array( 'TRADE_SUCCESS', 'TRADE_FINISHED' ), true ) ) {
                $renewal_order->payment_complete( $query['trade_no'] );
                $renewal_order->add_order_note( sprintf( __( '自动续费成功，交易号：%s', 'woo-alipay' ), $query['trade_no'] ) );
            } else {
                // 等待异步通知回调完成订单
                $renewal_order->add_order_note( __( '已发起支付宝代扣，等待异步通知确认。', 'woo-alipay' ) );
            }
        } catch ( Exception $e ) {
            self::log( __METHOD__ . ' exception: ' . $e->getMessage(), 'error' );
            $renewal_order->update_status( 'failed', $e->getMessage() );
        }
    }

    protected function setup_form_fields()
    {
        $this->form_fields = array(
            'basic_settings_title' => array(
                'title' => __('基本设置', 'woo-alipay'),
                'type' => 'title',
                'description' => __('配置支付网关的基本信息和显示设置', 'woo-alipay'),
            ),
            'enabled' => array(
                'title' => __('启用/禁用', 'woo-alipay'),
                'type' => 'checkbox',
                'label' => __('启用支付宝支付', 'woo-alipay'),
                'default' => 'no',
                'description' => __('勾选此项以在结账页面显示支付宝支付选项', 'woo-alipay'),
                'desc_tip' => false,
            ),
            'title' => array(
                'title' => __('结账页面标题', 'woo-alipay'),
                'type' => 'text',
                'default' => __('支付宝', 'woo-alipay'),
                'desc_tip' => __('在结账页面显示的支付方式名称', 'woo-alipay'),
            ),
            'description' => array(
                'title' => __('结账页面说明', 'woo-alipay'),
                'type' => 'textarea',
                'default' => __('通过支付宝付款（中国大陆，包括香港和澳门）。 如果您无法使用中国大陆支付宝帐户付款，请选择其他付款方式。', 'woo-alipay'),
                'desc_tip' => __('在结账页面支付方式下方显示的说明文字', 'woo-alipay'),
            ),
            
            'alipay_config_title' => array(
                'title' => __('支付宝应用配置', 'woo-alipay'),
                'type' => 'title',
                'description' => __('配置支付宝开放平台应用的相关参数', 'woo-alipay'),
            ),
            'appid' => array(
                'title' => __('支付宝应用ID', 'woo-alipay'),
                'type' => 'text',
                'description' => sprintf(
                    __('在支付宝开放平台获取的应用ID。%s', 'woo-alipay'),
                    '<a href="https://openhome.alipay.com/platform/developerIndex.htm" target="_blank">' . __('前往支付宝开放平台', 'woo-alipay') . '</a>'
                ),
                'placeholder' => '2016043001352080',
                'desc_tip' => false,
            ),
            'public_key' => array(
                'title' => __('支付宝公钥', 'woo-alipay'),
                'type' => 'textarea',
                'description' => sprintf(
                    __('支付宝公钥，在支付宝开放平台应用详情页面获取。%s', 'woo-alipay'),
                    '<a href="https://woocn.com/product/woo-alipay.html" target="_blank">' . __('查看配置教程', 'woo-alipay') . '</a>'
                ),
                'css' => 'min-height: 120px;',
                'placeholder' => 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMI...',
                'desc_tip' => false,
            ),
            'private_key' => array(
                'title' => __('支付宝商户应用程序私钥', 'woo-alipay'),
                'type' => 'textarea',
                'description' => sprintf(
                    __('应用私钥，使用支付宝密钥生成工具或openssl命令生成。<br/><strong>此密钥为机密信息，请勿泄露给任何人</strong>。%s', 'woo-alipay'),
                    '<a href="https://woocn.com/product/woo-alipay.html" target="_blank">' . __('查看密钥生成教程', 'woo-alipay') . '</a>'
                ),
                'css' => 'min-height: 120px;',
                'placeholder' => 'MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC...',
                'desc_tip' => false,
            ),
            
            'environment_title' => array(
                'title' => __('环境设置', 'woo-alipay'),
                'type' => 'title',
                'description' => __('配置运行环境和调试选项', 'woo-alipay'),
            ),
            'sandbox' => array(
                'title' => __('沙箱模式', 'woo-alipay'),
                'type' => 'checkbox',
                'label' => __('启用沙箱模式', 'woo-alipay'),
                'default' => 'no',
                'description' => sprintf(
                    __('启用沙箱模式进行测试，使用%s中的配置。', 'woo-alipay'), 
                    '<a href="https://openhome.alipay.com/platform/appDaily.htm" target="_blank">支付宝沙箱环境</a>'
                ),
                'desc_tip' => false,
            ),
            'debug' => array(
                'title' => __('调试日志', 'woo-alipay'),
                'type' => 'checkbox',
                'label' => __('启用日志记录', 'woo-alipay'),
                'default' => 'no',
                'description' => sprintf(
                    __('在%s内记录支付宝事件<br/><strong>注意：这可能会记录个人信息。 我们建议仅将其用于调试目的，并在完成后删除日志。</strong>', 'woo-alipay'), 
                    '<code>' . WC_Log_Handler_File::get_log_file_path($this->id) . '</code>'
                ),
                'desc_tip' => false,
            ),

        );

        // 将“支付增强功能”分组提前到“环境与调试”之后
        $this->add_payment_enhancement_settings();

        // 后续是“高级设置”和“订阅与自动续费（实验性）”
        $this->form_fields = array_merge(
            $this->form_fields,
            array(
                'advanced_settings_title' => array(
                    'title' => __('高级设置', 'woo-alipay'),
                    'type' => 'title',
                    'description' => __('配置汇率转换和连接测试等高级功能', 'woo-alipay'),
                ),
            )
        );

            // Subscriptions & Auto-renew (Experimental)

        // 订阅与自动续费（实验性）分组与字段
        $this->form_fields['subscriptions_title'] = array(
            'title' => __('订阅与自动续费（实验性）', 'woo-alipay'),
            'type'  => 'title',
            'description' => __('启用后，支持 WooCommerce Subscriptions 的自动续费。需要先在支付宝签约周期扣款。', 'woo-alipay'),
        );
        $this->form_fields['enable_auto_renew'] = array(
            'title' => __('启用自动续费', 'woo-alipay'),
            'type'  => 'checkbox',
            'label' => __('允许通过支付宝协议代扣进行订阅续费', 'woo-alipay'),
            'default' => 'no',
        );
        $this->form_fields['agreement_product_code'] = array(
            'title' => __('签约/代扣产品码', 'woo-alipay'),
            'type'  => 'text',
            'default' => 'CYCLE_PAY_AUTH',
            'description' => __('用于协议签约与代扣的产品码。不同商户开通能力可能不同，可通过过滤器覆盖。', 'woo-alipay'),
            'desc_tip' => true,
        );
        $this->form_fields['auto_renew_subject_prefix'] = array(
            'title' => __('续费订单标题前缀', 'woo-alipay'),
            'type'  => 'text',
            'default' => __('订阅续费', 'woo-alipay'),
        );
        
        // 仅当安装并启用了 WooCommerce Subscriptions 时，展示自动续费设置
        if ( ! ( class_exists('WC_Subscriptions') || function_exists('wcs_order_contains_subscription') || function_exists('wcs_is_subscription') ) ) {
            unset(
                $this->form_fields['enable_auto_renew'],
                $this->form_fields['agreement_product_code'],
                $this->form_fields['auto_renew_subject_prefix']
            );
            if ( isset( $this->form_fields['subscriptions_title'] ) ) {
                $this->form_fields['subscriptions_title']['description'] = __( '需要安装并启用 WooCommerce Subscriptions 才能设置自动续费。', 'woo-alipay' );
            }
        }

        if (!in_array($this->current_currency, $this->supported_currencies, true)) {
            $current_rate = $this->get_option('exchange_rate', '7.0');
            $description = sprintf(
                __('设置%s与人民币汇率（1 %s = %s 人民币）', 'woo-alipay'),
                $this->current_currency,
                $this->current_currency,
                $current_rate
            );

            $this->form_fields['exchange_rate'] = array(
                'title' => __('汇率', 'woo-alipay'),
                'type' => 'number',
                'css' => 'width: 120px;',
                'default' => '7.0',
                'custom_attributes' => array(
                    'step' => '0.01',
                    'min' => '0.01',
                ),
                'desc_tip' => $description,
            );
        }
        
        $this->form_fields['test_connection'] = array(
            'title' => __('测试连接', 'woo-alipay'),
            'type' => 'test_connection',
            'description' => __('发送消息给支付宝，以检查网关是否正确设置。', 'woo-alipay'),
            'desc_tip' => false,
        );
    }

    /**
     * Add payment enhancement settings
     */
    protected function add_payment_enhancement_settings()
    {
        // 支付增强功能标题
        $this->form_fields['payment_enhancement_title'] = array(
            'title' => __('支付增强功能', 'woo-alipay'),
            'type' => 'title',
            'description' => __('配置自动查询、超时处理、实时反馈和 Webhook 重试等增强功能', 'woo-alipay'),
        );
        
        // 订单状态自动查询
        $this->form_fields['enable_order_query'] = array(
            'title' => __('订单状态自动查询', 'woo-alipay'),
            'type' => 'checkbox',
            'label' => __('启用自动查询功能', 'woo-alipay'),
            'default' => 'yes',
            'description' => __('自动查询待支付订单的支付状态，避免用户支付成功但订单未更新的情况', 'woo-alipay'),
            'desc_tip' => false,
        );
        
        $this->form_fields['order_query_interval'] = array(
            'title' => __('查询间隔', 'woo-alipay'),
            'type' => 'select',
            'default' => '5',
            'options' => array(
                '5' => __('5 分钟', 'woo-alipay'),
                '10' => __('10 分钟', 'woo-alipay'),
                '15' => __('15 分钟', 'woo-alipay'),
                '30' => __('30 分钟', 'woo-alipay'),
            ),
            'description' => __('定时查询待支付订单的时间间隔', 'woo-alipay'),
            'desc_tip' => true,
        );
        
        $this->form_fields['order_query_time_range'] = array(
            'title' => __('查询时间范围', 'woo-alipay'),
            'type' => 'select',
            'default' => '24',
            'options' => array(
                '6' => __('6 小时内', 'woo-alipay'),
                '12' => __('12 小时内', 'woo-alipay'),
                '24' => __('24 小时内', 'woo-alipay'),
                '48' => __('48 小时内', 'woo-alipay'),
                '72' => __('72 小时内', 'woo-alipay'),
            ),
            'description' => __('只查询指定时间范围内创建的订单', 'woo-alipay'),
            'desc_tip' => true,
        );
        
        // 订单超时自动取消
        $this->form_fields['enable_order_timeout'] = array(
            'title' => __('订单超时自动取消', 'woo-alipay'),
            'type' => 'checkbox',
            'label' => __('启用超时取消功能', 'woo-alipay'),
            'default' => 'yes',
            'description' => __('自动取消超时未支付的订单，释放库存', 'woo-alipay'),
            'desc_tip' => false,
        );
        
        $this->form_fields['order_timeout'] = array(
            'title' => __('订单超时时间', 'woo-alipay'),
            'type' => 'number',
            'default' => '30',
            'description' => __('未支付订单的超时时间（分钟）。超时后订单将自动取消。', 'woo-alipay'),
            'desc_tip' => true,
            'custom_attributes' => array(
                'min' => '5',
                'max' => '1440',
                'step' => '5',
            ),
            'css' => 'width: 100px;',
        );
        
        // 支付状态实时反馈
        $this->form_fields['enable_payment_polling'] = array(
            'title' => __('支付状态实时反馈', 'woo-alipay'),
            'type' => 'checkbox',
            'label' => __('启用支付轮询功能', 'woo-alipay'),
            'default' => 'yes',
            'description' => __('在支付页面自动轮询检测支付完成状态，无需用户手动刷新', 'woo-alipay'),
            'desc_tip' => false,
        );
        
        $this->form_fields['polling_interval'] = array(
            'title' => __('轮询间隔', 'woo-alipay'),
            'type' => 'number',
            'default' => '3',
            'description' => __('每次查询的间隔时间（秒）', 'woo-alipay'),
            'desc_tip' => true,
            'custom_attributes' => array(
                'min' => '2',
                'max' => '10',
                'step' => '1',
            ),
            'css' => 'width: 100px;',
        );
        
        $this->form_fields['polling_max_attempts'] = array(
            'title' => __('最大轮询次数', 'woo-alipay'),
            'type' => 'number',
            'default' => '60',
            'description' => __('最多轮询次数，达到后停止轮询', 'woo-alipay'),
            'desc_tip' => true,
            'custom_attributes' => array(
                'min' => '10',
                'max' => '120',
                'step' => '10',
            ),
            'css' => 'width: 100px;',
        );
        
        // Webhook 重试机制
        $this->form_fields['enable_webhook_retry'] = array(
            'title' => __('Webhook 重试机制', 'woo-alipay'),
            'type' => 'checkbox',
            'label' => __('启用 Webhook 重试', 'woo-alipay'),
            'default' => 'yes',
            'description' => __('记录并自动重试失败的支付宝通知', 'woo-alipay'),
            'desc_tip' => false,
        );
        
        $this->form_fields['webhook_max_retries'] = array(
            'title' => __('最大重试次数', 'woo-alipay'),
            'type' => 'number',
            'default' => '5',
            'description' => __('Webhook 失败后的最大重试次数', 'woo-alipay'),
            'desc_tip' => true,
            'custom_attributes' => array(
                'min' => '1',
                'max' => '10',
                'step' => '1',
            ),
            'css' => 'width: 100px;',
        );
        
        $this->form_fields['webhook_retry_interval'] = array(
            'title' => __('重试间隔', 'woo-alipay'),
            'type' => 'select',
            'default' => '10',
            'options' => array(
                '5' => __('5 分钟', 'woo-alipay'),
                '10' => __('10 分钟', 'woo-alipay'),
                '15' => __('15 分钟', 'woo-alipay'),
                '30' => __('30 分钟', 'woo-alipay'),
                '60' => __('1 小时', 'woo-alipay'),
            ),
            'description' => __('两次重试之间的最小间隔时间', 'woo-alipay'),
            'desc_tip' => true,
        );
        
        $this->form_fields['webhook_log_retention'] = array(
            'title' => __('日志保留时间', 'woo-alipay'),
            'type' => 'select',
            'default' => '30',
            'options' => array(
                '7' => __('7 天', 'woo-alipay'),
                '15' => __('15 天', 'woo-alipay'),
                '30' => __('30 天', 'woo-alipay'),
                '60' => __('60 天', 'woo-alipay'),
                '90' => __('90 天', 'woo-alipay'),
                '0' => __('永久保留', 'woo-alipay'),
            ),
            'description' => __('Webhook 日志的保留时间，过期后自动删除', 'woo-alipay'),
            'desc_tip' => true,
        );
    }
    
    public function generate_test_connection_html($key, $data)
    {
        $field_key = $this->get_field_key($key);
        $defaults = array(
            'title' => '',
            'description' => '',
        );

        $data = wp_parse_args($data, $defaults);

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?></label>
            </th>
            <td class="forminp">
                <fieldset>
                    <legend class="screen-reader-text"><span><?php echo wp_kses_post($data['title']); ?></span></legend>
                    <?php wp_nonce_field('_woo_alipay_test_nonce', 'woo_alipay_nonce'); ?>
                    <button type="button" class="button-secondary" id="woo-alipay-test-connection">
                        <?php echo esc_html(__('现在测试', 'woo-alipay')); ?>
                    </button>
                    <div id="woo-alipay-test-result" style="margin-top: 10px;"></div>
                    <?php echo $this->get_description_html($data); ?>
                </fieldset>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /*******************************************************************
     * Protected methods
     *******************************************************************/

    protected function is_wooalipay_enabled()
    {
        $alipay_options = get_option('woocommerce_alipay_settings', array());

        return isset($alipay_options['enabled']) && ('yes' === $alipay_options['enabled']);
    }

    public function validate_settings()
    {
        $valid = true;

        if ($this->requires_exchange_rate() && !$this->exchange_rate) {
            add_action('admin_notices', array($this, 'missing_exchange_rate_notice'), 10, 0);

            $valid = false;
        }

        return $valid;
    }

    public function requires_exchange_rate()
    {

        return (!in_array($this->current_currency, $this->supported_currencies, true));
    }

    /*******************************************************************
     * Public methods
     *******************************************************************/

    public function is_available()
    {
        $is_available = ('yes' === $this->enabled) ? true : false;

        if (!$is_available) {
            return false;
        }

        if ('yes' === $this->get_option('sandbox')) {
            return true;
        }

        if ($this->multi_currency_enabled) {
            if (
                !in_array(get_woocommerce_currency(), $this->supported_currencies, true) &&
                !$this->exchange_rate
            ) {
                $is_available = false;
            }
        } elseif (
            !in_array($this->current_currency, $this->supported_currencies, true) &&
            !$this->exchange_rate
        ) {
            $is_available = false;
        }

        return $is_available;
    }

    public function process_admin_options()
    {
        $saved = parent::process_admin_options();

        if ('yes' !== $this->get_option('debug', 'no')) {

            if (empty(self::$log)) {
                self::$log = wc_get_logger();
            }

            self::$log->clear(self::GATEWAY_ID);
        }

        return $saved;
    }

    public function remember_refund_info($refund, $args)
    {
        $prefix = '';
        $suffix = '-' . current_time('timestamp');

        if (is_multisite()) {
            $prefix = get_current_blog_id() . '-';
        }

        self::$refund_id = str_pad($prefix . $refund->get_id() . $suffix, 64, '0', STR_PAD_LEFT);
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = new WC_Order($order_id);

        if (!$this->can_refund_order($order)) {
            return new WP_Error('error', __('Refund failed', 'woocommerce') . ' - ' . $this->refundable_status['reason']);
        }

        Woo_Alipay::require_lib('refund');

        $trade_no = $order->get_transaction_id();
        $total = $this->maybe_convert_amount($order->get_total());
        $amount = $this->maybe_convert_amount($amount);

        if (floatval($amount) <= 0 || floatval($amount) > floatval($total)) {
            return new WP_Error('error', __('Refund failed - incorrect refund amount (must be more than 0 and less than the total amount of the order).', 'woo-alipay'));
        }

        // 使用原始的out_trade_no，从订单元数据中获取
        $original_request = $order->get_meta('alipay_initalRequest');
        $out_trade_no = $original_request ? $original_request : 'WooA' . $order_id . '-' . current_time('timestamp');
        
        // 生成退款ID（如果self::$refund_id为空）
        if (empty(self::$refund_id)) {
            $prefix = is_multisite() ? get_current_blog_id() . '-' : '';
            $suffix = '-' . current_time('timestamp');
            self::$refund_id = str_pad($prefix . $order_id . $suffix, 64, '0', STR_PAD_LEFT);
        }
        
        $refund_result = $this->do_refund($out_trade_no, $trade_no, $amount, self::$refund_id, $reason, $order_id);

        if (!$refund_result instanceof WP_Error) {
            $result = true;

            $order->add_order_note(
                sprintf(
                /* translators: %1$s: Refund amount, %2$s: Payment method title, %3$s: Refund ID */
                    __('Refunded %1$s via %2$s - Refund ID: %3$s', 'woo-alipay'),
                    $amount,
                    $this->method_title,
                    '#' . ltrim(self::$refund_id, '0')
                )
            );
        } else {
            $result = $refund_result;
        }

        self::$refund_id = null;

        return $result;
    }

    public function can_refund_order($order)
    {
        $this->refundable_status = array(
            'refundable' => (bool)$order,
            'code' => ((bool)$order) ? 'ok' : 'invalid_order',
            'reason' => ((bool)$order) ? '' : __('Invalid order', 'woo-alipay'),
        );

        if ($order) {
            $alipay_transaction_closed = $order->meta_exists('alipay_transaction_closed');

            if ($alipay_transaction_closed) {
                $this->refundable_status['refundable'] = false;
                $this->refundable_status['code'] = 'alipay_transaction_closed';
                $this->refundable_status['reason'] = __('Alipay closed the transaction ; the refund needs to be handled by other means.', 'woo-alipay');
            } elseif (!$order->get_transaction_id()) {
                $this->refundable_status['refundable'] = false;
                $this->refundable_status['code'] = 'transaction_id';
                $this->refundable_status['reason'] = __('transaction not found.', 'woo-alipay');
            }
        }

        return $this->refundable_status['refundable'];
    }

    protected function maybe_convert_amount($amount)
    {
        $exchange_rate = $this->get_option('exchange_rate');
        $current_currency = get_option('woocommerce_currency');

        if (
            !in_array($current_currency, $this->supported_currencies, true) &&
            is_numeric($exchange_rate)
        ) {
            $amount = (int)($amount * 100);
            $amount = round($amount * $exchange_rate, 2);
            $amount = round(($amount / 100), 2);
        }

        return number_format($amount, 2, '.', '');
    }

    protected function do_refund($out_trade_no, $trade_no, $amount, $refund_id, $reason, $order_id = 0)
    {
        $refund_request_builder = new AlipayTradeRefundContentBuilder();

        $refund_request_builder->setOutTradeNo($out_trade_no);
        $refund_request_builder->setTradeNo($trade_no);
        $refund_request_builder->setRefundAmount($amount);
        $refund_request_builder->setOutRequestNo($refund_id);
        $refund_request_builder->setRefundReason(esc_html($reason));

        $config = $this->get_config($order_id);
        $aop = new AlipayTradeService($config);
        $response = $aop->Refund($refund_request_builder);

        if (10000 !== absint($response->code)) {
            self::log(__METHOD__ . ' Refund Error: ' . wc_print_r($response, true));
            $result = new WP_Error('error', $response->msg . '; ' . $response->sub_msg);
        } else {
            self::log(__METHOD__ . ' Refund Result: ' . wc_print_r($response, true));
            $result = $response;
        }

        return $result;
    }

    protected function get_config($order_id = 0)
    {
        $order = (0 === $order_id) ? false : new WC_Order($order_id);
        $config = array(
            'app_id' => $this->get_option('appid'),
            'merchant_private_key' => $this->get_option('private_key'),
            'notify_url' => apply_filters('woo_alipay_gateway_notify_url', $this->notify_url, $order_id),
            'return_url' => apply_filters('woo_alipay_gateway_return_url', ($order) ? $order->get_checkout_order_received_url() : get_home_url()),
            'charset' => $this->charset,
            'sign_type' => 'RSA2',
            'gatewayUrl' => ('yes' === $this->get_option('sandbox')) ? self::GATEWAY_SANDBOX_URL : self::GATEWAY_URL,
            'alipay_public_key' => $this->get_option('public_key'),
        );

        return $config;
    }

    protected static function log($message, $level = 'info', $force = false, $context = array())
    {
        if (self::$log_enabled || $force) {
            if (empty(self::$log)) {
                self::$log = wc_get_logger();
            }

            $default_context = array(
                'source' => self::GATEWAY_ID,
                'timestamp' => current_time('mysql'),
                'user_id' => get_current_user_id(),
                'ip_address' => WC_Geolocation::get_ip_address(),
            );

            $context = array_merge($default_context, $context);
            
            if (is_array($message) || is_object($message)) {
                $message = wc_print_r($message, true);
            }

            self::$log->log($level, $message, $context);
        }
    }

    public function sanitize_user_strict($username, $raw_username, $strict)
    {

        if (!$strict) {

            return $username;
        }

        return sanitize_user(stripslashes($raw_username), false);
    }

    public function missing_exchange_rate_notice()
    {
        $message = __('Aliay is enabled, but the store currency is not set to Chinese Yuan.', 'woo-alipay');
        // translators: %1$s is the URL of the link and %2$s is the currency name
        $message .= __(' Please <a href="%1$s">set the %2$s against the Chinese Yuan exchange rate</a>.', 'woo-alipay');

        $page = 'admin.php?page=wc-settings&tab=checkout&section=wc_alipay#woocommerce_alipay_exchange_rate';
        $url = admin_url($page);

        echo '<div class="error"><p>' . sprintf($message, $url, $this->current_currency . '</p></div>'); // WPCS: XSS OK
    }

    public function get_icon()
    {

        return '<span class="alipay"></span>';
    }

    public function receipt_page($order_id)
    {
        $order = new WC_Order($order_id);

        if (!$order || $order->is_paid()) {
            return;
        }

        Woo_Alipay::require_lib($this->is_mobile() ? 'payment_mobile' : 'payment_computer');

        $total = $this->maybe_convert_amount($order->get_total());

        if ($this->is_mobile()) {
            $pay_request_builder = new AlipayTradeWapPayContentBuilder();
        } else {
            $pay_request_builder = new AlipayTradePagePayContentBuilder();
        }

        $pay_request_builder->setBody($this->get_order_title($order, true));
        $pay_request_builder->setSubject($this->get_order_title($order));
        $pay_request_builder->setTotalAmount($total);
        $pay_request_builder->setOutTradeNo('WooA' . $order_id . '-' . current_time('timestamp'));

        if ($this->is_mobile()) {
            $pay_request_builder->setTimeExpress('15m');
        }

        $config = $this->get_config($order_id);
        $aop = new AlipayTradeService($config);
        $dispatcher_form = false;
        global $wpdb;

        try {
            ob_start();

            if ($this->is_mobile()) {
                $html = $aop->wapPay($pay_request_builder, $config['return_url'], $config['notify_url']);
            } else {
                $html = $aop->pagePay($pay_request_builder, $config['return_url'], $config['notify_url']);
            }

            $order->add_meta_data('alipay_initalRequest', $pay_request_builder->getOutTradeNo(), true);
            $order->save();

            set_query_var('dispatcher_form', ob_get_clean());

        } catch (Exception $e) {
            $message = ' Caught an exception when trying to generate the Alipay redirection form: ';
            self::log(__METHOD__ . $message . wc_print_r($e, true), 'error');
            $order->update_status('failed', $e->getMessage());
            WC()->cart->empty_cart();
        }

        ob_start();

        Woo_Alipay::locate_template('redirected-pay.php', true, true);

        $html = ob_get_clean();

        echo $html; // WPCS: XSS OK
    }

    protected function is_mobile()
    {
        $ua = strtolower($_SERVER['HTTP_USER_AGENT']);

        if (strpos($ua, 'ipad') || strpos($ua, 'iphone') || strpos($ua, 'android')) {

            return true;
        }

        return false;
    }

    protected function get_order_title($order, $desc = false)
    {
        $title = get_option('blogname');
        $order_items = $order->get_items();

        if ($order_items && 0 < count($order_items)) {
            $title = '#' . $order->get_id() . ' ';
            $index = 0;
            foreach ($order_items as $item_id => $item) {

                if ($index > 0 && !$desc) {
                    $title .= '...';

                    break;
                } else {

                    if (0 < $index) {
                        $title .= '; ';
                    }

                    $title .= $item['name'];
                }

                $index++;
            }
        }

        $title = str_replace('%', '', $title);

        if ($desc && 128 < mb_strlen($title)) {
            $title = mb_substr($title, 0, 125) . '...';
        } elseif (256 < mb_strlen($title)) {
            $title = mb_substr($title, 0, 253) . '...';
        }

        return $title;
    }

    public function admin_options()
    {
        echo '<h3>' . esc_html(__('Alipay payment gateway by Woo Alipay', 'woo-alipay')) . '</h3>';
        echo '<p>' . esc_html(__('Alipay is a simple, secure and fast online payment method.', 'woo-alipay')) . '</p>';
        
        echo '<div class="notice notice-info inline">';
        echo '<p>';
        printf(
            __('需要配置帮助？请查看 %1$s 获取详细的配置指南和文档。', 'woo-alipay'),
            '<a href="https://woocn.com/document/woo-alipay" target="_blank">' . __('官方文档', 'woo-alipay') . '</a>'
        );
        echo '</p>';
        echo '</div>';


        // 实用端点与工具
        $notify_url = apply_filters( 'woo_alipay_gateway_notify_url', $this->notify_url, 0 );
        echo '<div class="card" style="padding:12px; margin:12px 0;">';
        echo '<h2 style="margin-top:0;">' . esc_html__( '工具与端点', 'woo-alipay' ) . '</h2>';
        echo '<p>' . esc_html__( '异步通知 URL（请在支付宝开放平台中配置为支付结果通知 URL）', 'woo-alipay' ) . '</p>';
        echo '<p><code id="woo-alipay-notify-url">' . esc_html( $notify_url ) . '</code> ';
        echo '<button type="button" class="button" onclick="wooAlipayCopy(\'#woo-alipay-notify-url\')">' . esc_html__( '复制', 'woo-alipay' ) . '</button></p>';

        // 若支持订阅，展示签约相关端点
        $subs_available = ( class_exists('WC_Subscriptions') || function_exists('wcs_order_contains_subscription') || function_exists('wcs_is_subscription') );
        if ( $subs_available ) {
            $start_url   = WC()->api_request_url( 'WC_Alipay_Agreement_Start' );
            $notify_agre = WC()->api_request_url( 'WC_Alipay_Agreement_Notify' );
            $return_agre = WC()->api_request_url( 'WC_Alipay_Agreement_Return' );
            echo '<hr/>';
            echo '<p>' . esc_html__( '签约端点（用于支付宝协议授权与回调）', 'woo-alipay' ) . '</p>';
            echo '<ul style="margin-left:1em;">';
            echo '<li>' . esc_html__( '签约启动', 'woo-alipay' ) . ': <code id="woo-alipay-sign-start">' . esc_html( $start_url ) . '</code> <button type="button" class="button" onclick="wooAlipayCopy(\'#woo-alipay-sign-start\')">' . esc_html__( '复制', 'woo-alipay' ) . '</button></li>';
            echo '<li>' . esc_html__( '签约通知', 'woo-alipay' ) . ': <code id="woo-alipay-sign-notify">' . esc_html( $notify_agre ) . '</code> <button type="button" class="button" onclick="wooAlipayCopy(\'#woo-alipay-sign-notify\')">' . esc_html__( '复制', 'woo-alipay' ) . '</button></li>';
            echo '<li>' . esc_html__( '签约返回', 'woo-alipay' ) . ': <code id="woo-alipay-sign-return">' . esc_html( $return_agre ) . '</code> <button type="button" class="button" onclick="wooAlipayCopy(\'#woo-alipay-sign-return\')">' . esc_html__( '复制', 'woo-alipay' ) . '</button></li>';
            echo '</ul>';
        }
        echo '</div>';

        echo '<table class="form-table woo-alipay-settings">';
        $this->generate_settings_html();
        echo '</table>';

        // 简易复制函数
        echo '<script type="text/javascript">function wooAlipayCopy(sel){try{var el=document.querySelector(sel);if(!el)return;var r=document.createRange();r.selectNode(el);var s=window.getSelection();s.removeAllRanges();s.addRange(r);document.execCommand("copy");s.removeAllRanges();}catch(e){console&&console.error(e);}}</script>';
    }

    // Provider state methods for WooCommerce Payments list badges
    public function is_account_connected() {
        return (bool) ( $this->get_option('appid') && $this->get_option('private_key') && $this->get_option('public_key') );
    }

    public function needs_setup() {
        return ! $this->is_account_connected();
    }

    public function is_test_mode() {
        return 'yes' === $this->get_option('sandbox');
    }

    public function check_alipay_response()
    {
        $response_data = $_POST; // @codingStandardsIgnoreLine
        $out_trade_no = isset($_POST['out_trade_no']) ? sanitize_text_field($_POST['out_trade_no']) : '';
        $response_app_id = isset($_POST['app_id']) ? sanitize_text_field($_POST['app_id']) : '';
        $trade_status = isset($_POST['trade_status']) ? sanitize_text_field($_POST['trade_status']) : '';
        $transaction_id = isset($_POST['trade_no']) ? sanitize_text_field($_POST['trade_no']) : '';
        $response_total = isset($_POST['total_amount']) ? sanitize_text_field($_POST['total_amount']) : '';
        $fund_bill_list = isset($_POST['fund_bill_list']) ? stripslashes(sanitize_text_field($_POST['fund_bill_list'])) : '';
        $needs_reply = false;
        $error = false;

        // 先尝试通过既有规则（WooA前缀）解析订单ID
        $order = null;
        $order_id = 0;
        if ( strpos( $out_trade_no, 'WooA' ) === 0 ) {
            $out_trade_no_parts = explode('-', str_replace('WooA', '', $out_trade_no));
            $order_id = absint(array_shift($out_trade_no_parts));
            $order = wc_get_order($order_id);
        }
        // 若无法解析或未找到订单，改为通过订单元数据定位（适配自动续费 SubR*）
        if ( ! $order ) {
            $orders = wc_get_orders( array( 'meta_key' => '_alipay_out_trade_no', 'meta_value' => $out_trade_no, 'limit' => 1 ) );
            if ( ! empty( $orders ) ) {
                $order = $orders[0];
                $order_id = $order->get_id();
            }
        }
        $order_check = ($order instanceof WC_Order);
        
        if (!$order_check) {
            self::log(__METHOD__ . ' Order #' . $order_id . ' not found.', 'error');
            return;
        }
        
        $config = $this->get_config($order_id);
        $response_data['fund_bill_list'] = stripslashes($response_data['fund_bill_list']);
        $order_total = $this->maybe_convert_amount($order->get_total());
        
        // 处理退款通知：如果包含refund_fee，则验证原始订单金额
        $refund_fee = isset($_POST['refund_fee']) ? sanitize_text_field($_POST['refund_fee']) : '';
        if (!empty($refund_fee) && floatval($refund_fee) > 0) {
            // 这是退款通知，验证原始订单金额
            $total_amount_check = ($order_total === $response_total);
        } else {
            // 这是支付通知，正常验证
            $total_amount_check = ($order_total === $response_total);
        }
        
        $response_app_id_check = ($response_app_id === $config['app_id']);

        $order->add_meta_data('alipay_initalResponse', json_encode($_POST), true);

        Woo_Alipay::require_lib('check_notification');

        $aop = new AlipayTradeService($config);
        $result_check_signature = $aop->check($response_data);

        self::log(__METHOD__ . ' Alipay response raw data: ' . wc_print_r($response_data, true));

        if ($order_check && $result_check_signature && $response_app_id_check && $total_amount_check) {

            if ('TRADE_FINISHED' === $trade_status || 'TRADE_SUCCESS' === $trade_status) {
                $needs_reply = true;

                add_filter('woocommerce_valid_order_statuses_for_payment', array(
                    $this,
                    'valid_order_statuses_for_payment'
                ), 10, 1);

                if ($order->needs_payment()) {
                    self::log(__METHOD__ . ' Found order #' . $order_id);
                    $order->payment_complete(wc_clean($transaction_id));
                    $order->add_order_note(__('Alipay payment completed', 'woo-alipay'));
                    WC()->cart->empty_cart();
                } else {
                    $order->add_order_note(__('Alipay notified the payment was successful but the order was already paid for. Please double check that the payment was recorded properly.', 'woo-alipay'));
                }

                remove_filter('woocommerce_valid_order_statuses_for_payment', array(
                    $this,
                    'valid_order_statuses_for_payment'
                ), 10);

                if ('TRADE_FINISHED' === $trade_status) {
                    $order->update_meta_data('alipay_transaction_closed', true);
                    $order->save_meta_data();
                }
            } elseif ('TRADE_CLOSED' === $trade_status) {
                $needs_reply = true;

                add_filter('woocommerce_valid_order_statuses_for_payment', array(
                    $this,
                    'valid_order_statuses_for_payment'
                ), 10, 1);

                if ($order->needs_payment()) {
                    $order->add_order_note(__('Alipay closed the transaction and the order is no longer valid for payment.', 'woo-alipay'));
                    $this->order_cancel($order);
                    self::log(__METHOD__ . ' Found order #' . $order_id . ' and changed status to "cancelled".', 'error');
                }

                remove_filter('woocommerce_valid_order_statuses_for_payment', array(
                    $this,
                    'valid_order_statuses_for_payment'
                ), 10);
                $order->update_meta_data('alipay_transaction_closed', true);
                $order->save_meta_data();
            } elseif ('WAIT_BUYER_PAY' === $trade_status) {
                $order->add_order_note(__('Alipay notified it is waiting for payment.', 'woo-alipay'));
            }
        } else {
            $error = __('Invalid Alipay response: ', 'woo-alipay');

            if ($order_check) {

                if (!$response_app_id_check) {
                    $error .= 'mismatched_app_id';
                } elseif (!$result_check_signature) {
                    $error .= 'invalid_response_signature';
                } elseif (!$total_amount_check) {
                    $error .= 'invalid_response_total_amount';
                }

                $order->update_status('failed', $error);
                self::log(__METHOD__ . ' Found order #' . $order_id . ' and changed status to "failed".', 'error');
            } else {
                self::log(__METHOD__ . ' Alipay error - Order not found after payment.', 'error', true);

                if ($response_app_id_check && $result_check_signature) {

                    if ('TRADE_SUCCESS' === $trade_status) {
                        $refund_result = $this->do_refund(
                            $out_trade_no,
                            $transaction_id,
                            $response_total,
                            str_pad('WooA' . current_time('timestamp'), 64, '0', STR_PAD_LEFT),
                            __('Woo Alipay error: WooCommerce Order not found.', 'woo-alipay')
                        );

                        if (!$refund_result instanceof WP_Error) {
                            $message = ' Missing order #' . $order_id;
                            $message .= ', Alipay transaction #' . $transaction_id . ' successfully refunded.';

                            self::log(__METHOD__ . $message, 'info', true);
                        } else {
                            $message = ' Missing order #' . $order_id;
                            $message .= ', Alipay transaction #' . $transaction_id . ' could not be refunded.';
                            $message .= " Reason: \n";
                            $message .= 'there was an error while trying to automatically refund the order.';
                            $message .= ' Error details: ' . wc_print_r($refund_result);

                            self::log(__METHOD__ . $message, 'error', true);
                            do_action(
                                'wooalipay_orphan_transaction_notification',
                                $order_id,
                                $transaction_id,
                                WC_Log_Handler_File::get_log_file_path($this->id),
                                'auto_refund_error'
                            );
                        }
                    } elseif ('TRADE_CLOSED' === $trade_status || 'TRADE_FINISHED' === $trade_status) {
                        $message = ' Missing order #' . $order_id;
                        $message .= ', Alipay transaction #' . $transaction_id . ' could not be refunded.';
                        $message .= " Reason: \n";
                        $message .= 'Alipay already closed the transaction.';
                        $message .= ' Alipay response raw data: ' . wc_print_r($response_data);

                        self::log(__METHOD__ . $message, 'error', true);
                        do_action(
                            'wooalipay_orphan_transaction_notification',
                            $order_id,
                            $transaction_id,
                            WC_Log_Handler_File::get_log_file_path($this->id),
                            'transaction_closed'
                        );
                    }
                }
            }
        }

        if ($needs_reply) {
            echo (!$error) ? 'success' : 'fail'; // WPCS: XSS OK
        }

        exit();
    }

    protected function order_cancel($order)
    {

        if ('on-hold' === $order->get_status()) {
            $updated = $order->update_status('cancel');

            if (!$updated) {

                return new WP_Error(__METHOD__, __('Update status event failed.', 'woocommerce'));
            }
        }

        return true;
    }

    public function valid_order_statuses_for_payment($statuses)
    {
        $statuses[] = 'on-hold';

        return $statuses;
    }

    public function process_payment($order_id)
    {
        try {
            $order = wc_get_order($order_id);
            
            if (!$order) {
                throw new Exception(__('订单不存在', 'woo-alipay'));
            }

            if (!$this->validate_currency($order)) {
                throw new Exception(__('不支持的货币类型', 'woo-alipay'));
            }

            $order->update_status('pending', __('等待支付宝支付', 'woo-alipay'));

            $redirect = $order->get_checkout_payment_url(true);
            
            return array(
                'result' => 'success',
                'redirect' => $redirect,
            );
            
        } catch (Exception $e) {
            wc_add_notice($e->getMessage(), 'error');
            $this->log('Payment processing error: ' . $e->getMessage());
            
            return array(
                'result' => 'failure',
                'messages' => $e->getMessage(),
            );
        }
    }

    private function validate_currency($order)
    {
        $currency = $order->get_currency();
        
        if (in_array($currency, $this->supported_currencies, true)) {
            return true;
        }
        
        if ($this->requires_exchange_rate() && $this->exchange_rate > 0) {
            return true;
        }
        
        return false;
    }

    public function test_connection()
    {

        if (
            !isset($_POST['nonce']) ||
            !wp_verify_nonce($_POST['nonce'], '_woo_alipay_test_nonce')
        ) {
            wp_send_json_error('安全验证失败');
        } else {
            $result = $this->execute_dummy_query();

            if ($result === true) {
                wp_send_json_success('连接测试成功');
            } else {
                wp_send_json_error($result ?: '配置错误或网络问题');
            }
        }

        wp_die();
    }

    protected function execute_dummy_query()
    {
        Woo_Alipay::require_lib('dummy_query');

        $config = $this->get_config();
        $aop = new AlipayTradeService($config);
        $biz_content = '{"out_trade_no":"00000000000000000"}';
        $request = new AlipayTradeQueryRequest();

        $request->setBizContent($biz_content);

        $response = $aop->aopclientRequestExecute($request);
        $response = $response->alipay_trade_query_response;

        if (
            is_object($response) &&
            isset($response->code, $response->sub_code) &&
            '40004' === $response->code &&
            'ACQ.TRADE_NOT_EXIST' === $response->sub_code
        ) {
            self::log(__METHOD__ . ': ' . 'Dummy query to Alipay successful');
            return true;
        } else {
            $error_msg = '支付宝响应异常';
            if (isset($response->code)) {
                $error_msg .= '（错误代码：' . $response->code . '）';
            }
            if (isset($response->msg)) {
                $error_msg .= ' - ' . $response->msg;
            }
            self::log(__METHOD__ . ': ' . wc_print_r($response, true));
            return $error_msg;
        }
    }

    protected function order_hold($order)
    {

        if ('pending' === $order->get_status()) {
            $updated = $order->update_status('on-hold');

            if (!$updated) {

                return new WP_Error(__METHOD__, __('Update status event failed.', 'woocommerce'));
            }
        }

        return true;
    }

}