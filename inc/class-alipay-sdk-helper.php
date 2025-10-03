<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Alipay SDK 辅助类
 * 封装常用的 SDK 调用方法，供各个支付网关使用
 */
class Alipay_SDK_Helper
{
    /**
     * 获取支付宝配置
     * 
     * @param array $settings 网关设置
     * @return array
     */
    public static function get_alipay_config($settings)
    {
        $is_sandbox = isset($settings['sandbox']) && 'yes' === $settings['sandbox'];
        
        return array(
            'app_id' => $settings['appid'] ?? '',
            'merchant_private_key' => $settings['private_key'] ?? '',
            'alipay_public_key' => $settings['public_key'] ?? '',
            'charset' => 'UTF-8',
            'sign_type' => 'RSA2',
            'gatewayUrl' => $is_sandbox ? 
                'https://openapi.alipaydev.com/gateway.do' : 
                'https://openapi.alipay.com/gateway.do',
        );
    }

    /**
     * 创建支付宝服务对象
     * 
     * @param array $config 支付宝配置
     * @return AlipayTradeService|null
     */
    public static function create_alipay_service($config)
    {
        try {
            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
            
            $aop = new AopClient();
            $aop->gatewayUrl = $config['gatewayUrl'];
            $aop->appId = $config['app_id'];
            $aop->rsaPrivateKey = $config['merchant_private_key'];
            $aop->alipayrsaPublicKey = $config['alipay_public_key'];
            $aop->charset = $config['charset'];
            $aop->signType = $config['sign_type'];
            
            return $aop;
        } catch (Exception $e) {
            self::log('创建支付宝服务失败: ' . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * 验证支付宝通知签名
     * 
     * @param array $params 通知参数
     * @param string $alipay_public_key 支付宝公钥
     * @return bool
     */
    public static function verify_notify($params, $alipay_public_key)
    {
        try {
            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
            
            $aop = new AopClient();
            $aop->alipayrsaPublicKey = $alipay_public_key;
            
            return $aop->rsaCheckV1($params, null, 'RSA2');
        } catch (Exception $e) {
            self::log('验证签名失败: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * 查询订单状态
     * 
     * @param string $out_trade_no 商户订单号
     * @param string $trade_no 支付宝交易号
     * @param array $config 支付宝配置
     * @return array|WP_Error
     */
    public static function query_order($out_trade_no, $trade_no, $config)
    {
        try {
            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradeQueryRequest.php';
            
            $aop = self::create_alipay_service($config);
            if (!$aop) {
                return new WP_Error('sdk_error', '创建支付宝服务失败');
            }
            
            $request = new AlipayTradeQueryRequest();
            $biz_content = array();
            
            if ($out_trade_no) {
                $biz_content['out_trade_no'] = $out_trade_no;
            }
            if ($trade_no) {
                $biz_content['trade_no'] = $trade_no;
            }
            
            $request->setBizContent(json_encode($biz_content));
            $response = $aop->execute($request);
            
            $response_node = 'alipay_trade_query_response';
            $result = $response->$response_node;
            
            if (isset($result->code) && $result->code === '10000') {
                return array(
                    'success' => true,
                    'trade_no' => $result->trade_no ?? '',
                    'out_trade_no' => $result->out_trade_no ?? '',
                    'trade_status' => $result->trade_status ?? '',
                    'total_amount' => $result->total_amount ?? 0,
                );
            } else {
                return new WP_Error(
                    'query_failed',
                    $result->sub_msg ?? $result->msg ?? '查询订单失败'
                );
            }
        } catch (Exception $e) {
            self::log('查询订单异常: ' . $e->getMessage(), 'error');
            return new WP_Error('exception', $e->getMessage());
        }
    }

    /**
     * 关闭订单
     * 
     * @param string $out_trade_no 商户订单号
     * @param array $config 支付宝配置
     * @return array|WP_Error
     */
    public static function close_order($out_trade_no, $config)
    {
        try {
            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
            require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradeCloseRequest.php';
            
            $aop = self::create_alipay_service($config);
            if (!$aop) {
                return new WP_Error('sdk_error', '创建支付宝服务失败');
            }
            
            $request = new AlipayTradeCloseRequest();
            $biz_content = array('out_trade_no' => $out_trade_no);
            $request->setBizContent(json_encode($biz_content));
            
            $response = $aop->execute($request);
            $response_node = 'alipay_trade_close_response';
            $result = $response->$response_node;
            
            if (isset($result->code) && $result->code === '10000') {
                return array('success' => true);
            } else {
                return new WP_Error(
                    'close_failed',
                    $result->sub_msg ?? $result->msg ?? '关闭订单失败'
                );
            }
        } catch (Exception $e) {
            self::log('关闭订单异常: ' . $e->getMessage(), 'error');
            return new WP_Error('exception', $e->getMessage());
        }
    }

    /**
     * 货币转换
     * 
     * @param float $amount 金额
     * @param string $from_currency 原货币
     * @param float $exchange_rate 汇率
     * @return float
     */
    public static function convert_currency($amount, $from_currency, $exchange_rate)
    {
        $supported_currencies = array('RMB', 'CNY');
        
        if (in_array(strtoupper($from_currency), $supported_currencies, true)) {
            return round(floatval($amount), 2);
        }
        
        return round(floatval($amount) * floatval($exchange_rate), 2);
    }

    /**
     * 生成商户订单号
     * 
     * @param int $order_id WooCommerce 订单 ID
     * @param string $prefix 前缀
     * @return string
     */
    public static function generate_out_trade_no($order_id, $prefix = '')
    {
        $blog_prefix = is_multisite() ? get_current_blog_id() . '-' : '';
        $timestamp = current_time('timestamp');
        
        if (empty($prefix)) {
            $prefix = 'WooA';
        }
        
        return $prefix . $blog_prefix . $order_id . '-' . $timestamp;
    }

    /**
     * 记录日志
     * 
     * @param string $message 日志信息
     * @param string $level 日志级别
     */
    public static function log($message, $level = 'info')
    {
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->log($level, $message, array('source' => 'alipay'));
        }
    }

    /**
     * 获取设备类型
     * 
     * @return string 'mobile' 或 'pc'
     */
    public static function get_device_type()
    {
        if (wp_is_mobile()) {
            return 'mobile';
        }
        return 'pc';
    }

    /**
     * 格式化金额
     * 
     * @param float $amount 金额
     * @return string
     */
    public static function format_amount($amount)
    {
        return number_format(floatval($amount), 2, '.', '');
    }
}
