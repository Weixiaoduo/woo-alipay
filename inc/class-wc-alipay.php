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
        );

        self::$log_enabled = ('yes' === $this->get_option('debug', 'no'));

        if (!in_array($this->charset, array('gbk', 'utf-8'), true)) {
            $this->charset = 'utf-8';
        }

        $this->setup_form_fields();
        $this->init_settings();

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
            
            'advanced_settings_title' => array(
                'title' => __('高级设置', 'woo-alipay'),
                'type' => 'title',
                'description' => __('配置汇率转换和连接测试等高级功能', 'woo-alipay'),
            ),
        );

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
            'notify_url' => $this->notify_url,
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

        echo '<table class="form-table woo-alipay-settings">';

        $this->generate_settings_html();

        echo '</table>';
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
        $out_trade_no_parts = explode('-', str_replace('WooA', '', $out_trade_no));
        $order_id = absint(array_shift($out_trade_no_parts));
        $order = wc_get_order($order_id);
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