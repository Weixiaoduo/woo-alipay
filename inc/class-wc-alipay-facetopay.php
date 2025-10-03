<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 支付宝当面付支付网关
 * 
 * 支持扫码支付功能，商家展示二维码供用户扫描支付
 * 适用于收银台、实体店等线下场景
 */
class WC_Alipay_FaceToPay extends WC_Payment_Gateway
{
    const GATEWAY_ID = 'alipay_facetopay';
    
    protected static $log_enabled = false;
    protected static $log = false;
    
    protected $current_currency;
    protected $exchange_rate;
    protected $order_prefix;
    protected $notify_url;
    protected $charset;

    public function __construct()
    {
        $this->id = self::GATEWAY_ID;
        $this->method_title = __('支付宝当面付', 'woo-alipay');
        $this->method_description = __('支持当面付扫码支付，商家展示二维码供用户扫描。适用于收银台、实体店等场景。需要在支付宝商户后台开通当面付功能。', 'woo-alipay');
        $this->icon = WOO_ALIPAY_PLUGIN_URL . 'assets/images/alipay-icon.svg';
        $this->has_fields = false;
        $this->charset = strtolower(get_bloginfo('charset'));
        
        if (!in_array($this->charset, array('gbk', 'utf-8'), true)) {
            $this->charset = 'utf-8';
        }
        
        $this->init_form_fields();
        $this->init_settings();
        
        $this->title = $this->get_option('title', __('支付宝扫码支付', 'woo-alipay'));
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->current_currency = get_option('woocommerce_currency');
        $this->exchange_rate = $this->get_option('exchange_rate');
        $this->order_prefix = $this->get_option('order_prefix', 'F2F');
        $this->notify_url = WC()->api_request_url('WC_Alipay_FaceToPay');
        
        self::$log_enabled = ('yes' === $this->get_option('debug', 'no'));
        
        $this->supports = array(
            'products',
            'refunds',
        );
        
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        add_action('woocommerce_api_wc_alipay_facetopay', array($this, 'check_alipay_response'));
        add_action('wp_ajax_alipay_facetopay_query', array($this, 'ajax_query_payment_status'));
        add_action('wp_ajax_nopriv_alipay_facetopay_query', array($this, 'ajax_query_payment_status'));
        add_action('wp_ajax_alipay_facetopay_refresh_qrcode', array($this, 'ajax_refresh_qrcode'));
        add_action('wp_ajax_nopriv_alipay_facetopay_refresh_qrcode', array($this, 'ajax_refresh_qrcode'));
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('启用/禁用', 'woo-alipay'),
                'type' => 'checkbox',
                'label' => __('启用支付宝当面付', 'woo-alipay'),
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('标题', 'woo-alipay'),
                'type' => 'text',
                'description' => __('用户在结账时看到的支付方式名称', 'woo-alipay'),
                'default' => __('支付宝扫码支付', 'woo-alipay'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('描述', 'woo-alipay'),
                'type' => 'textarea',
                'description' => __('支付方式描述', 'woo-alipay'),
                'default' => __('使用支付宝扫描二维码完成支付', 'woo-alipay'),
                'desc_tip' => true,
            ),
            
            'qrcode_settings' => array(
                'title' => __('二维码设置', 'woo-alipay'),
                'type' => 'title',
            ),
            'qrcode_size' => array(
                'title' => __('二维码尺寸', 'woo-alipay'),
                'type' => 'number',
                'description' => __('二维码显示尺寸（像素）', 'woo-alipay'),
                'default' => '300',
                'desc_tip' => true,
                'custom_attributes' => array(
                    'min' => '100',
                    'max' => '500',
                    'step' => '10',
                ),
            ),
            'qrcode_timeout' => array(
                'title' => __('二维码有效期', 'woo-alipay'),
                'type' => 'select',
                'description' => __('二维码的有效时间', 'woo-alipay'),
                'default' => '120',
                'options' => array(
                    '60' => __('1分钟', 'woo-alipay'),
                    '120' => __('2分钟', 'woo-alipay'),
                    '180' => __('3分钟', 'woo-alipay'),
                    '300' => __('5分钟', 'woo-alipay'),
                    '600' => __('10分钟', 'woo-alipay'),
                ),
                'desc_tip' => true,
            ),
            
            'polling_settings' => array(
                'title' => __('轮询设置', 'woo-alipay'),
                'type' => 'title',
            ),
            'polling_interval' => array(
                'title' => __('轮询间隔', 'woo-alipay'),
                'type' => 'number',
                'description' => __('查询支付状态的间隔时间（秒）', 'woo-alipay'),
                'default' => '2',
                'desc_tip' => true,
                'custom_attributes' => array(
                    'min' => '1',
                    'max' => '10',
                    'step' => '1',
                ),
            ),
            
            'order_prefix' => array(
                'title' => __('订单号前缀', 'woo-alipay'),
                'type' => 'text',
                'description' => __('当面付订单号的前缀', 'woo-alipay'),
                'default' => 'F2F',
                'desc_tip' => true,
            ),
            
            'debug' => array(
                'title' => __('调试日志', 'woo-alipay'),
                'type' => 'checkbox',
                'label' => __('启用日志记录', 'woo-alipay'),
                'default' => 'no',
                'description' => sprintf(
                    __('记录当面付相关日志到 %s', 'woo-alipay'),
                    '<code>' . WC_Log_Handler_File::get_log_file_path($this->id) . '</code>'
                ),
            ),
        );
        
        if (!in_array($this->current_currency, array('CNY', 'RMB'), true)) {
            $this->form_fields['exchange_rate'] = array(
                'title' => __('汇率', 'woo-alipay'),
                'type' => 'number',
                'description' => sprintf(
                    __('设置 %s 与人民币的汇率', 'woo-alipay'),
                    $this->current_currency
                ),
                'default' => '7.0',
                'desc_tip' => true,
                'custom_attributes' => array(
                    'step' => '0.01',
                    'min' => '0.01',
                ),
            );
        }
    }

    public function payment_scripts()
    {
        if (!is_checkout() && !is_order_received_page()) {
            return;
        }
        
        wp_enqueue_style(
            'alipay-facetopay',
            WOO_ALIPAY_PLUGIN_URL . 'css/alipay-facetopay.css',
            array(),
            WOO_ALIPAY_VERSION
        );
        
        wp_enqueue_script(
            'qrcodejs',
            'https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js',
            array(),
            '1.0.0',
            true
        );
        
        wp_enqueue_script(
            'alipay-facetopay',
            WOO_ALIPAY_PLUGIN_URL . 'js/alipay-facetopay.js',
            array('jquery', 'qrcodejs'),
            WOO_ALIPAY_VERSION,
            true
        );
        
        wp_localize_script('alipay-facetopay', 'alipayFaceToPayParams', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'polling_interval' => intval($this->get_option('polling_interval', 2)) * 1000,
            'timeout' => intval($this->get_option('qrcode_timeout', 120)),
        ));
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        
        $order->update_status('pending', __('等待扫码支付', 'woo-alipay'));
        WC()->cart->empty_cart();
        
        return array(
            'result' => 'success',
            'redirect' => $order->get_checkout_payment_url(true),
        );
    }

    public function receipt_page($order_id)
    {
        $order = wc_get_order($order_id);
        
        if (!$order || $order->is_paid()) {
            return;
        }
        
        require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        
        $main_gateway = new WC_Alipay(false);
        $config = Alipay_SDK_Helper::get_alipay_config(array(
            'appid' => $main_gateway->get_option('appid'),
            'private_key' => $main_gateway->get_option('private_key'),
            'public_key' => $main_gateway->get_option('public_key'),
            'sandbox' => $main_gateway->get_option('sandbox'),
        ));
        
        $aop = Alipay_SDK_Helper::create_alipay_service($config);
        if (!$aop) {
            wc_add_notice(__('支付初始化失败', 'woo-alipay'), 'error');
            return;
        }
        
        $total = $this->convert_to_rmb($order->get_total());
        $out_trade_no = Alipay_SDK_Helper::generate_out_trade_no($order_id, $this->order_prefix);
        
        $order->update_meta_data('_alipay_out_trade_no', $out_trade_no);
        $order->save();
        
        try {
            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradePrecreateRequest.php';
            
            $request = new AlipayTradePrecreateRequest();
            
            $biz_content = array(
                'out_trade_no' => $out_trade_no,
                'total_amount' => Alipay_SDK_Helper::format_amount($total),
                'subject' => $this->get_order_title($order),
                'body' => $this->get_order_description($order),
                'timeout_express' => $this->get_option('qrcode_timeout', 120) . 's',
            );
            
            $request->setBizContent(json_encode($biz_content));
            $request->setNotifyUrl($this->notify_url);
            
            $response = $aop->execute($request);
            $response_node = 'alipay_trade_precreate_response';
            $result = $response->$response_node;
            
            if (isset($result->code) && $result->code === '10000') {
                $qr_code = $result->qr_code;
                $order->update_meta_data('_alipay_qrcode', $qr_code);
                $order->save();
                
                self::log('当面付二维码生成成功: ' . $out_trade_no);
                
                $this->display_qrcode_page($order, $qr_code);
            } else {
                self::log('当面付二维码生成失败: ' . ($result->sub_msg ?? $result->msg), 'error');
                wc_add_notice(__('生成支付二维码失败', 'woo-alipay'), 'error');
            }
            
        } catch (Exception $e) {
            self::log('当面付异常: ' . $e->getMessage(), 'error');
            wc_add_notice(__('支付请求失败', 'woo-alipay'), 'error');
        }
    }

    protected function display_qrcode_page($order, $qr_code)
    {
        $qrcode_size = intval($this->get_option('qrcode_size', 300));
        $timeout = intval($this->get_option('qrcode_timeout', 120));
        
        include WOO_ALIPAY_PLUGIN_PATH . 'inc/templates/payment-qrcode.php';
    }

    public function ajax_refresh_qrcode()
    {
        check_ajax_referer('alipay_facetopay_query', 'nonce');
        $order_id = intval($_POST['order_id'] ?? 0);
        if (!$order_id) {
            wp_send_json_error(array('message' => 'Invalid order ID'));
        }
        $order = wc_get_order($order_id);
        if (!$order || $order->is_paid()) {
            wp_send_json_error(array('message' => 'Invalid order'));
        }
        require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        $main_gateway = new WC_Alipay(false);
        $config = Alipay_SDK_Helper::get_alipay_config(array(
            'appid' => $main_gateway->get_option('appid'),
            'private_key' => $main_gateway->get_option('private_key'),
            'public_key' => $main_gateway->get_option('public_key'),
            'sandbox' => $main_gateway->get_option('sandbox'),
        ));
        try {
            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradePrecreateRequest.php';
            $aop = Alipay_SDK_Helper::create_alipay_service($config);
            if (!$aop) {
                wp_send_json_error(array('message' => 'SDK init failed'));
            }
            $out_trade_no = Alipay_SDK_Helper::generate_out_trade_no($order_id, $this->order_prefix);
            $order->update_meta_data('_alipay_out_trade_no', $out_trade_no);
            $order->save();
            $request = new AlipayTradePrecreateRequest();
            $biz_content = array(
                'out_trade_no' => $out_trade_no,
                'total_amount' => Alipay_SDK_Helper::format_amount($this->convert_to_rmb($order->get_total())),
                'subject' => $this->get_order_title($order),
                'body' => $this->get_order_description($order),
                'timeout_express' => $this->get_option('qrcode_timeout', 120) . 's',
            );
            $request->setBizContent(json_encode($biz_content));
            $request->setNotifyUrl($this->notify_url);
            $response = $aop->execute($request);
            $response_node = 'alipay_trade_precreate_response';
            $result = $response->$response_node;
            if (isset($result->code) && $result->code === '10000') {
                $qr_code = $result->qr_code;
                $order->update_meta_data('_alipay_qrcode', $qr_code);
                $order->save();
                wp_send_json_success(array(
                    'qr_code' => $qr_code,
                    'timeout' => intval($this->get_option('qrcode_timeout', 120))
                ));
            }
            wp_send_json_error(array('message' => 'Precreate failed'));
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    public function ajax_query_payment_status()
    {
        check_ajax_referer('alipay_facetopay_query', 'nonce');
        
        $order_id = intval($_POST['order_id'] ?? 0);
        
        if (!$order_id) {
            wp_send_json_error(array('message' => 'Invalid order ID'));
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(array('message' => 'Order not found'));
        }
        
        if ($order->is_paid()) {
            wp_send_json_success(array(
                'status' => 'paid',
                'redirect_url' => $order->get_checkout_order_received_url(),
            ));
        }
        
        require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        
        $main_gateway = new WC_Alipay(false);
        $config = Alipay_SDK_Helper::get_alipay_config(array(
            'appid' => $main_gateway->get_option('appid'),
            'private_key' => $main_gateway->get_option('private_key'),
            'public_key' => $main_gateway->get_option('public_key'),
            'sandbox' => $main_gateway->get_option('sandbox'),
        ));
        
        $out_trade_no = $order->get_meta('_alipay_out_trade_no');
        $result = Alipay_SDK_Helper::query_order($out_trade_no, '', $config);
        
        if (!is_wp_error($result) && $result['success']) {
            if (in_array($result['trade_status'], array('TRADE_SUCCESS', 'TRADE_FINISHED'), true)) {
                if (!$order->is_paid()) {
                    $order->payment_complete($result['trade_no']);
                    $order->add_order_note(
                        sprintf(__('当面付支付完成 - 交易号: %s', 'woo-alipay'), $result['trade_no'])
                    );
                }
                
                wp_send_json_success(array(
                    'status' => 'paid',
                    'redirect_url' => $order->get_checkout_order_received_url(),
                ));
            }
        }
        
        wp_send_json_success(array('status' => 'pending'));
    }

    public function check_alipay_response()
    {
        require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        
        $main_gateway = new WC_Alipay(false);
        $alipay_public_key = $main_gateway->get_option('public_key');
        
        if (!Alipay_SDK_Helper::verify_notify($_POST, $alipay_public_key)) {
            self::log('当面付通知签名验证失败', 'error');
            echo 'fail';
            exit;
        }
        
        $out_trade_no = $_POST['out_trade_no'] ?? '';
        $trade_no = $_POST['trade_no'] ?? '';
        $trade_status = $_POST['trade_status'] ?? '';
        
        self::log('当面付支付通知: ' . print_r($_POST, true));
        
        $orders = wc_get_orders(array(
            'meta_key' => '_alipay_out_trade_no',
            'meta_value' => $out_trade_no,
            'limit' => 1,
        ));
        
        if (empty($orders)) {
            self::log('未找到订单: ' . $out_trade_no, 'error');
            echo 'fail';
            exit;
        }
        
        $order = $orders[0];
        
        if ($trade_status === 'TRADE_SUCCESS' || $trade_status === 'TRADE_FINISHED') {
            if (!$order->is_paid()) {
                $order->payment_complete($trade_no);
                $order->add_order_note(
                    sprintf(__('当面付支付完成 - 交易号: %s', 'woo-alipay'), $trade_no)
                );
            }
            echo 'success';
        } else {
            echo 'fail';
        }
        
        exit;
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new WP_Error('error', __('订单不存在', 'woo-alipay'));
        }
        
        require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
        require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradeRefundRequest.php';
        
        $main_gateway = new WC_Alipay(false);
        $config = Alipay_SDK_Helper::get_alipay_config(array(
            'appid' => $main_gateway->get_option('appid'),
            'private_key' => $main_gateway->get_option('private_key'),
            'public_key' => $main_gateway->get_option('public_key'),
            'sandbox' => $main_gateway->get_option('sandbox'),
        ));
        
        $aop = Alipay_SDK_Helper::create_alipay_service($config);
        if (!$aop) {
            return new WP_Error('sdk_error', __('创建支付宝服务失败', 'woo-alipay'));
        }
        
        $out_trade_no = $order->get_meta('_alipay_out_trade_no');
        if (!$out_trade_no) {
            $out_trade_no = Alipay_SDK_Helper::generate_out_trade_no($order_id, $this->order_prefix);
        }
        $refund_amount = $amount ? floatval($amount) : floatval($order->get_total());
        $refund_reason = $reason ? $reason : __('订单退款', 'woo-alipay');
        
        try {
            $request = new AlipayTradeRefundRequest();
            $biz_content = array(
                'out_trade_no' => $out_trade_no,
                'refund_amount' => Alipay_SDK_Helper::format_amount($refund_amount),
                'refund_reason' => $refund_reason,
            );
            $request->setBizContent(json_encode($biz_content));
            $response = $aop->execute($request);
            $node = 'alipay_trade_refund_response';
            $result = $response->$node;
            if (isset($result->code) && $result->code === '10000') {
                $order->add_order_note(sprintf(__('支付宝退款成功，金额：¥%s', 'woo-alipay'), number_format($refund_amount, 2)));
                return true;
            }
            return new WP_Error('refund_failed', $result->sub_msg ?? $result->msg ?? __('退款失败', 'woo-alipay'));
        } catch (Exception $e) {
            return new WP_Error('exception', $e->getMessage());
        }
    }

    /**
     * 检查支付方式是否可用
     */
    public function is_available()
    {
        $is_available = ('yes' === $this->enabled) ? true : false;

        if (!$is_available) {
            return false;
        }

        // 检查主支付宝网关是否配置
        $main_gateway = new WC_Alipay(false);
        if (!$main_gateway->get_option('appid') || !$main_gateway->get_option('private_key')) {
            return false;
        }

        return $is_available;
    }

    protected function convert_to_rmb($amount)
    {
        require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        return Alipay_SDK_Helper::convert_currency(
            $amount,
            $this->current_currency,
            $this->exchange_rate
        );
    }

    protected function get_order_title($order)
    {
        $title = get_bloginfo('name') . ' - ' . sprintf(__('订单 #%s', 'woo-alipay'), $order->get_id());
        return mb_substr($title, 0, 256);
    }

    protected function get_order_description($order)
    {
        $items = array();
        foreach ($order->get_items() as $item) {
            $items[] = $item->get_name();
        }
        return mb_substr(implode(', ', $items), 0, 400);
    }

    protected static function log($message, $level = 'info')
    {
        if (self::$log_enabled) {
            if (empty(self::$log)) {
                self::$log = wc_get_logger();
            }
            self::$log->log($level, $message, array('source' => self::GATEWAY_ID));
        }
    }
}
