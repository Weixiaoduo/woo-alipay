/**
 * 花呗分期支付前端脚本
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        // 确保至少选中一个分期选项
        const $installmentRadios = $('input[name="alipay_installment_period"]');
        
        if ($installmentRadios.length > 0) {
            // 如果没有任何选中的，选中第一个
            if ($installmentRadios.filter(':checked').length === 0) {
                $installmentRadios.first().prop('checked', true);
            }
            
            // 添加点击动画效果
            $installmentRadios.on('change', function() {
                $(this).closest('label').addClass('period-selected');
                $installmentRadios.not(this).closest('label').removeClass('period-selected');
            });
        }
        
        // 在提交订单前验证是否选择了分期期数
        $('form.checkout').on('checkout_place_order_alipay_installment', function() {
            const $selectedPeriod = $('input[name="alipay_installment_period"]:checked');
            
            if ($selectedPeriod.length === 0) {
                alert('请选择分期期数');
                return false;
            }
            
            return true;
        });
    });
    
})(jQuery);
