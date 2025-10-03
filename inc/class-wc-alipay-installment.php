<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * 花呗分期支付网关
 * 
 * 支持花呗分期付款功能，用户可选择3期、6期、12期分期
 */
class WC_Alipay_Installment extends WC_Payment_Gateway
{
    const GATEWAY_ID = 'alipay_installment';
    
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
        $this->method_title = __('支付宝花呗分期', 'woo-alipay');
        $this->method_description = __('支持花呗分期付款，用户可选择3期、6期、12期分期。需要在支付宝商户后台开通花呗分期功能。', 'woo-alipay');
        $this->icon = WOO_ALIPAY_PLUGIN_URL . 'assets/images/alipay-icon.svg';
        $this->has_fields = true; // 在结账页面显示分期选择
        $this->charset = strtolower(get_bloginfo('charset'));
        
        if (!in_array($this->charset, array('gbk', 'utf-8'), true)) {
            $this->charset = 'utf-8';
        }
        
        // 加载设置
        $this->init_form_fields();
        $this->init_settings();
        
        // 设置属性
        $this->title = $this->get_option('title', __('支付宝花呗分期', 'woo-alipay'));
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->current_currency = get_option('woocommerce_currency');
        $this->exchange_rate = $this->get_option('exchange_rate');
        $this->order_prefix = $this->get_option('order_prefix', 'HBFQ');
        $this->notify_url = WC()->api_request_url('WC_Alipay_Installment');
        
        self::$log_enabled = ('yes' === $this->get_option('debug', 'no'));
        
        // 支持的功能
        $this->supports = array(
            'products',
            'refunds',
        );
        
        // 添加钩子
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        add_action('woocommerce_api_wc_alipay_installment', array($this, 'check_alipay_response'));
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
    }

    /**
     * 初始化表单字段
     */
    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('启用/禁用', 'woo-alipay'),
                'type' => 'checkbox',
                'label' => __('启用支付宝花呗分期', 'woo-alipay'),
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('标题', 'woo-alipay'),
                'type' => 'text',
                'description' => __('用户在结账时看到的支付方式名称', 'woo-alipay'),
                'default' => __('支付宝花呗分期', 'woo-alipay'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('描述', 'woo-alipay'),
                'type' => 'textarea',
                'description' => __('支付方式描述，显示在结账页面', 'woo-alipay'),
                'default' => __('使用支付宝花呗分期付款，支持3期、6期、12期免息或低息分期。', 'woo-alipay'),
                'desc_tip' => true,
            ),
            
            'alipay_config' => array(
                'title' => __('支付宝配置', 'woo-alipay'),
                'type' => 'title',
                'description' => __('使用主支付宝网关的配置（App ID、公钥、私钥等）', 'woo-alipay'),
            ),
            
            'installment_settings' => array(
                'title' => __('分期设置', 'woo-alipay'),
                'type' => 'title',
            ),
            'show_interest_free_badge' => array(
                'title' => __('显示“免息”标识', 'woo-alipay'),
                'type' => 'checkbox',
                'label' => __('在分期选项后显示“免息”标识（当商家承担手续费时）', 'woo-alipay'),
                'default' => 'yes',
                'desc_tip' => true,
            ),
            'fee_bearer' => array(
                'title' => __('分期手续费承担方', 'woo-alipay'),
                'type' => 'select',
                'description' => __('选择分期手续费由谁承担。用户承担将会在支付宝端向用户收取手续费；商家承担则由商家承担。', 'woo-alipay'),
                'default' => 'user',
                'options' => array(
                    'user' => __('用户承担', 'woo-alipay'),
                    'seller' => __('商家承担', 'woo-alipay'),
                ),
                'desc_tip' => true,
            ),
            'min_amount_6' => array(
                'title' => __('6期最小金额', 'woo-alipay'),
                'type' => 'number',
                'description' => __('达到此金额才显示6期选项（留空或0表示不限制）', 'woo-alipay'),
                'default' => '',
                'desc_tip' => true,
                'custom_attributes' => array(
                    'min' => '0',
                    'step' => '1',
                ),
            ),
            'min_amount_12' => array(
                'title' => __('12期最小金额', 'woo-alipay'),
                'type' => 'number',
                'description' => __('达到此金额才显示12期选项（留空或0表示不限制）', 'woo-alipay'),
                'default' => '',
                'desc_tip' => true,
                'custom_attributes' => array(
                    'min' => '0',
                    'step' => '1',
                ),
            ),
            'min_amount' => array(
                'title' => __('最小分期金额', 'woo-alipay'),
                'type' => 'number',
                'description' => __('订单金额低于此值时不显示分期选项（人民币）', 'woo-alipay'),
                'default' => '100',
                'desc_tip' => true,
                'custom_attributes' => array(
                    'min' => '1',
                    'step' => '1',
                ),
            ),
            'available_periods' => array(
                'title' => __('可用分期期数', 'woo-alipay'),
                'type' => 'multiselect',
                'description' => __('选择允许的分期期数', 'woo-alipay'),
                'default' => array('3', '6', '12'),
                'options' => array(
                    '3' => __('3期', 'woo-alipay'),
                    '6' => __('6期', 'woo-alipay'),
                    '12' => __('12期', 'woo-alipay'),
                ),
                'desc_tip' => true,
                'class' => 'wc-enhanced-select',
            ),
            'default_period' => array(
                'title' => __('默认分期期数', 'woo-alipay'),
                'type' => 'select',
                'description' => __('用户未选择时的默认分期期数', 'woo-alipay'),
                'default' => '3',
                'options' => array(
                    '3' => __('3期', 'woo-alipay'),
                    '6' => __('6期', 'woo-alipay'),
                    '12' => __('12期', 'woo-alipay'),
                ),
                'desc_tip' => true,
            ),
            'blocks_insufficient_behavior' => array(
                'title' => __('结账区块金额不足行为', 'woo-alipay'),
                'type' => 'select',
                'description' => __('当订单金额低于最小分期金额时，在结账区块中隐藏支付方式或显示提示。', 'woo-alipay'),
                'default' => 'hide',
                'options' => array(
                    'hide' => __('隐藏（推荐）', 'woo-alipay'),
                    'show' => __('显示提示', 'woo-alipay'),
                ),
                'desc_tip' => true,
            ),
            
            'order_prefix' => array(
                'title' => __('订单号前缀', 'woo-alipay'),
                'type' => 'text',
                'description' => __('花呗分期订单号的前缀，便于区分', 'woo-alipay'),
                'default' => 'HBFQ',
                'desc_tip' => true,
            ),
            
            'debug' => array(
                'title' => __('调试日志', 'woo-alipay'),
                'type' => 'checkbox',
                'label' => __('启用日志记录', 'woo-alipay'),
                'default' => 'no',
                'description' => sprintf(
                    __('记录花呗分期相关日志到 %s', 'woo-alipay'),
                    '<code>' . WC_Log_Handler_File::get_log_file_path($this->id) . '</code>'
                ),
            ),
        );
        
        // 如果不是人民币，添加汇率设置
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

    /**
     * 在结账页面显示分期选择
     */
    public function payment_fields()
    {
        // 显示描述
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }
        
        // 获取购物车总额
        $cart_total = WC()->cart->get_total('');
        $min_amount = floatval($this->get_option('min_amount', 100));
        $available_periods = $this->get_option('available_periods', array('3', '6', '12'));
        $default_period = $this->get_option('default_period', '3');
        
        // 转换为人民币
        $rmb_total = $this->convert_to_rmb($cart_total);
        
        if ($rmb_total < $min_amount) {
            echo '<p class="alipay-installment-notice">' . 
                sprintf(__('订单金额需满 ¥%s 才能使用花呗分期', 'woo-alipay'), $min_amount) . 
                '</p>';
            return;
        }
        
        // 动态过滤可用分期期数（根据阈值）
        $filtered_periods = array();
        foreach ($available_periods as $p) {
            $p = (string) $p;
            if ($p === '6') {
                $min6 = floatval($this->get_option('min_amount_6', 0));
                if ($min6 > 0 && $rmb_total < $min6) { continue; }
            }
            if ($p === '12') {
                $min12 = floatval($this->get_option('min_amount_12', 0));
                if ($min12 > 0 && $rmb_total < $min12) { continue; }
            }
            $filtered_periods[] = $p;
        }
        ?>
        <div class="alipay-installment-selector">
            <p><strong><?php _e('选择分期期数：', 'woo-alipay'); ?></strong></p>
            <ul class="installment-periods">
                <?php foreach ($filtered_periods as $period) : ?>
                    <li>
                        <label>
                            <input type="radio" 
                                   name="alipay_installment_period" 
                                   value="<?php echo esc_attr($period); ?>"
                                   <?php checked($period, $default_period); ?> />
                            <span class="period-label">
                                <?php echo sprintf(__('%s期', 'woo-alipay'), $period); ?>
                            </span>
                            <span class="period-amount">
                                <?php 
                                $monthly = $rmb_total / intval($period);
                                echo sprintf(__('每期 ¥%s', 'woo-alipay'), number_format($monthly, 2)); 
                                ?>
                            </span>
                            <?php if ( $this->get_option('fee_bearer', 'user') === 'seller' && 'yes' === $this->get_option('show_interest_free_badge', 'yes') ) : ?>
                                <span class="alipay-badge alipay-badge--free">
                                    <?php _e('免息', 'woo-alipay'); ?>
                                </span>
                            <?php endif; ?>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="alipay-installment-fee-note">
                <?php 
                $fee_bearer = $this->get_option('fee_bearer', 'user');
                echo $fee_bearer === 'seller' 
                    ? __('免息分期（手续费由商家承担）', 'woo-alipay') 
                    : __('可能产生分期手续费，以支付宝支付页面为准', 'woo-alipay');
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * 加载前端脚本
     */
    public function payment_scripts()
    {
        if (!is_checkout()) {
            return;
        }
        
        wp_enqueue_style(
            'alipay-installment',
            WOO_ALIPAY_PLUGIN_URL . 'css/alipay-installment.css',
            array(),
            WOO_ALIPAY_VERSION
        );
        
        wp_enqueue_script(
            'alipay-installment',
            WOO_ALIPAY_PLUGIN_URL . 'js/alipay-installment.js',
            array('jquery'),
            WOO_ALIPAY_VERSION,
            true
        );
    }

    /**
     * 处理支付
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        
        // 获取分期期数 - 支持传统表单和 Blocks
        $installment_period = $this->get_option('default_period', '3');
        
        // 从传统表单获取
        if (isset($_POST['alipay_installment_period'])) {
            $installment_period = sanitize_text_field($_POST['alipay_installment_period']);
        }
        // 从 Blocks 获取
        elseif (isset($_POST['payment_data'])) {
            $payment_data = json_decode(wp_unslash($_POST['payment_data']), true);
            if (isset($payment_data['alipay_installment_period'])) {
                $installment_period = sanitize_text_field($payment_data['alipay_installment_period']);
            }
        }
        
        // 保存分期期数到订单
        $order->update_meta_data('_alipay_installment_period', $installment_period);
        $order->save();
        
        // 标记订单为待支付
        $order->update_status('pending', __('等待花呗分期支付', 'woo-alipay'));
        
        // 清空购物车
        WC()->cart->empty_cart();
        
        // 跳转到支付页面
        return array(
            'result' => 'success',
            'redirect' => $order->get_checkout_payment_url(true),
        );
    }

    /**
     * 收银页面 - 生成支付表单
     */
    public function receipt_page($order_id)
    {
        $order = wc_get_order($order_id);
        
        if (!$order || $order->is_paid()) {
            return;
        }
        
        // 加载辅助类
        require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        
        // 获取主支付宝网关配置
        $main_gateway = new WC_Alipay(false);
        $config = Alipay_SDK_Helper::get_alipay_config(array(
            'appid' => $main_gateway->get_option('appid'),
            'private_key' => $main_gateway->get_option('private_key'),
            'public_key' => $main_gateway->get_option('public_key'),
            'sandbox' => $main_gateway->get_option('sandbox'),
        ));
        
        // 创建支付宝服务
        $aop = Alipay_SDK_Helper::create_alipay_service($config);
        if (!$aop) {
            $order->update_status('failed', __('创建支付宝服务失败', 'woo-alipay'));
            wc_add_notice(__('支付初始化失败，请稍后重试', 'woo-alipay'), 'error');
            return;
        }
        
        // 获取订单信息
        $total = $this->convert_to_rmb($order->get_total());
        $installment_period = $order->get_meta('_alipay_installment_period');
        $out_trade_no = Alipay_SDK_Helper::generate_out_trade_no($order_id, $this->order_prefix);
        
        // 保存商户订单号
        $order->update_meta_data('_alipay_out_trade_no', $out_trade_no);
        $order->save();
        
        try {
            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradePagePayRequest.php';
            
            $request = new AlipayTradePagePayRequest();
            
            // 设置业务参数
            $biz_content = array(
                'out_trade_no' => $out_trade_no,
                'total_amount' => Alipay_SDK_Helper::format_amount($total),
                'subject' => $this->get_order_title($order),
                'body' => $this->get_order_description($order),
                'product_code' => 'FAST_INSTANT_TRADE_PAY',
                // 花呗分期参数
                'enable_pay_channels' => 'pcreditpayInstallment',
                'extend_params' => array(
                    'hb_fq_num' => $installment_period,
'hb_fq_seller_percent' => ($this->get_option('fee_bearer', 'user') === 'seller' ? '100' : '0'),
                ),
            );
            
            $request->setBizContent(json_encode($biz_content));
            $request->setReturnUrl($order->get_checkout_order_received_url());
            $request->setNotifyUrl($this->notify_url);
            
            // 生成支付表单
            $html = $aop->pageExecute($request, 'POST');
            
            self::log('花呗分期支付请求: ' . print_r($biz_content, true));
            
            echo $html;
            
        } catch (Exception $e) {
            self::log('花呗分期支付异常: ' . $e->getMessage(), 'error');
            $order->update_status('failed', $e->getMessage());
            wc_add_notice(__('支付请求失败，请稍后重试', 'woo-alipay'), 'error');
        }
    }

    /**
     * 检查支付宝响应
     */
    public function check_alipay_response()
    {
        // 加载辅助类
        require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        
        // 获取支付宝公钥
        $main_gateway = new WC_Alipay(false);
        $alipay_public_key = $main_gateway->get_option('public_key');
        
        // 验证签名
        if (!Alipay_SDK_Helper::verify_notify($_POST, $alipay_public_key)) {
            self::log('花呗分期通知签名验证失败', 'error');
            echo 'fail';
            exit;
        }
        
        $out_trade_no = $_POST['out_trade_no'] ?? '';
        $trade_no = $_POST['trade_no'] ?? '';
        $trade_status = $_POST['trade_status'] ?? '';
        
        self::log('花呗分期支付通知: ' . print_r($_POST, true));
        
        // 查找订单
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
        
        // 处理支付结果
        if ($trade_status === 'TRADE_SUCCESS' || $trade_status === 'TRADE_FINISHED') {
            if (!$order->is_paid()) {
                $order->payment_complete($trade_no);
                $order->add_order_note(
                    sprintf(__('花呗分期支付完成 - 交易号: %s', 'woo-alipay'), $trade_no)
                );
            }
            echo 'success';
        } else {
            echo 'fail';
        }
        
        exit;
    }

    /**
     * 处理退款
     */
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

        // 检查最小金额要求
        if (WC()->cart) {
            $cart_total = WC()->cart->get_total('');
            $min_amount = floatval($this->get_option('min_amount', 100));
            $rmb_total = $this->convert_to_rmb($cart_total);
            
            if ($rmb_total < $min_amount) {
                return false;
            }
        }

        return $is_available;
    }

    /**
     * 转换为人民币
     */
    protected function convert_to_rmb($amount)
    {
        return Alipay_SDK_Helper::convert_currency(
            $amount,
            $this->current_currency,
            $this->exchange_rate
        );
    }

    /**
     * 获取订单标题
     */
    protected function get_order_title($order)
    {
        $title = get_bloginfo('name') . ' - ' . sprintf(__('订单 #%s', 'woo-alipay'), $order->get_id());
        return mb_substr($title, 0, 256);
    }

    /**
     * 获取订单描述
     */
    protected function get_order_description($order)
    {
        $items = array();
        foreach ($order->get_items() as $item) {
            $items[] = $item->get_name();
        }
        return mb_substr(implode(', ', $items), 0, 400);
    }

    /**
     * 记录日志
     */
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
