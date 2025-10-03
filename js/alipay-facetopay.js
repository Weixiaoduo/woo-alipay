/**
 * 当面付支付前端脚本
 */

(function($) {
    'use strict';
    
    let pollingTimer = null;
    let timeoutTimer = null;
    let remainingTime = 0;
    let pollingAttempts = 0;
    let refreshing = false;
    const maxPollingAttempts = 100; // 最多轮询100次
    
    const AlipayFaceToPay = {
        init: function() {
            const $qrcodeElement = $('#alipay-qrcode');
            
            if ($qrcodeElement.length > 0 && typeof alipayFaceToPayParams !== 'undefined') {
                const qrcodeData = $qrcodeElement.data('qrcode');
                const qrcodeSize = parseInt($qrcodeElement.data('size'), 10) || 300;
                const orderId = $qrcodeElement.data('order-id');
                
                if (qrcodeData) {
                    this.generateQRCode(qrcodeData, qrcodeSize);
                    this.startPolling(orderId);
                    this.startTimeout();
                }
            }
        },
        
        generateQRCode: function(data, size) {
            try {
                new QRCode(document.getElementById('alipay-qrcode'), {
                    text: data,
                    width: size,
                    height: size,
                    colorDark: '#000000',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.H
                });
            } catch (error) {
                console.error('生成二维码失败:', error);
                this.showError('生成二维码失败，请刷新页面重试');
            }
        },
        
        startPolling: function(orderId) {
            const self = this;
            const nonce = $('#alipay-facetopay-nonce').val();
            
            pollingTimer = setInterval(function() {
                if (pollingAttempts >= maxPollingAttempts) {
                    self.stopPolling();
                    return;
                }
                
                pollingAttempts++;
                
                $.ajax({
                    url: alipayFaceToPayParams.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'alipay_facetopay_query',
                        order_id: orderId,
                        nonce: nonce
                    },
                    success: function(response) {
                        if (response.success && response.data.status === 'paid') {
                            self.stopPolling();
                            self.showSuccess();
                            
                            setTimeout(function() {
                                window.location.href = response.data.redirect_url;
                            }, 1500);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('查询支付状态失败:', error);
                    }
                });
            }, alipayFaceToPayParams.polling_interval || 2000);
        },
        
        stopPolling: function() {
            if (pollingTimer) {
                clearInterval(pollingTimer);
                pollingTimer = null;
            }
        },
        
        startTimeout: function() {
            const self = this;
            remainingTime = alipayFaceToPayParams.timeout || 120;
            
            this.updateTimer();
            
            timeoutTimer = setInterval(function() {
                remainingTime--;
                self.updateTimer();

                // 即将过期时自动刷新二维码
                if (remainingTime <= 5 && !refreshing) {
                    self.refreshQRCode();
                }
                
                if (remainingTime <= 0) {
                    self.stopPolling();
                    clearInterval(timeoutTimer);
                    self.showExpired();
                }
            }, 1000);
        },
        
        updateTimer: function() {
            const $timer = $('.alipay-qrcode-timer');
            
            if ($timer.length > 0) {
                const minutes = Math.floor(remainingTime / 60);
                const seconds = remainingTime % 60;
                const timeStr = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
                
                $timer.text('剩余时间: ' + timeStr);
                
                if (remainingTime <= 30) {
                    $timer.addClass('warning');
                }
            }
        },
        
        showSuccess: function() {
            const $status = $('.alipay-payment-status');
            
            $status.removeClass('error').addClass('success');
            $status.html(
                '<span class="status-icon">✓</span>' +
                '<span class="status-text">支付成功！正在跳转...</span>'
            );
        },
        
        showError: function(message) {
            const $status = $('.alipay-payment-status');
            
            $status.removeClass('success').addClass('error');
            $status.html(
                '<span class="status-icon">✕</span>' +
                '<span class="status-text">' + message + '</span>'
            );
        },
        
        refreshQRCode: function() {
            const self = this;
            if (refreshing) return;
            const $qrcodeElement = $('#alipay-qrcode');
            const orderId = $qrcodeElement.data('order-id');
            const nonce = $('#alipay-facetopay-nonce').val();
            refreshing = true;
            $.ajax({
                url: alipayFaceToPayParams.ajax_url,
                type: 'POST',
                data: {
                    action: 'alipay_facetopay_refresh_qrcode',
                    order_id: orderId,
                    nonce: nonce
                },
                success: function(response) {
                    refreshing = false;
                    if (response.success && response.data.qr_code) {
                        // 重新渲染二维码
                        $('#alipay-qrcode').empty();
                        const size = parseInt($qrcodeElement.data('size'), 10) || 300;
                        $('#alipay-qrcode').attr('data-qrcode', response.data.qr_code);
                        AlipayFaceToPay.generateQRCode(response.data.qr_code, size);
                        // 重置计时
                        remainingTime = parseInt(response.data.timeout || 120, 10);
                    }
                },
                error: function() { refreshing = false; }
            });
        },
        
        showExpired: function() {
            const $container = $('.alipay-qrcode-container');
            
            $container.html(
                '<div class="alipay-qrcode-expired">' +
                    '<div class="expired-icon">⏱</div>' +
                    '<div class="expired-text">二维码已过期</div>' +
                    '<button class="refresh-button" onclick="location.reload()">刷新页面</button>' +
                '</div>'
            );
        }
    };
    
    $(document).ready(function() {
        AlipayFaceToPay.init();
    });
    
    // 页面卸载时停止轮询
    $(window).on('beforeunload', function() {
        if (pollingTimer) {
            clearInterval(pollingTimer);
        }
        if (timeoutTimer) {
            clearInterval(timeoutTimer);
        }
    });
    
})(jQuery);
