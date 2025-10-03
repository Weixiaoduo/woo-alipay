/**
 * Alipay Payment Status Polling
 * 
 * Polls order payment status and redirects when payment is confirmed
 * 
 * @package Woo_Alipay
 * @since 3.3.0
 */

(function($) {
    'use strict';

    var AlipayPaymentPolling = {
        /**
         * Poll interval in milliseconds
         */
        pollInterval: (typeof woo_alipay_polling !== 'undefined' && woo_alipay_polling.poll_interval) ? woo_alipay_polling.poll_interval : 3000,

        /**
         * Maximum poll attempts
         */
        maxAttempts: (typeof woo_alipay_polling !== 'undefined' && woo_alipay_polling.max_attempts) ? woo_alipay_polling.max_attempts : 60,

        /**
         * Current attempt count
         */
        currentAttempt: 0,

        /**
         * Poll timer
         */
        timer: null,

        /**
         * Order ID
         */
        orderId: null,

        /**
         * Initialize
         */
        init: function() {
            // Get order ID from URL or data attribute
            this.orderId = this.getOrderId();

            if (!this.orderId) {
                return;
            }

            // Start polling after a short delay (to allow Alipay redirect to complete)
            setTimeout(function() {
                AlipayPaymentPolling.startPolling();
            }, 5000);

            // Also poll when user returns to the page
            $(window).on('focus', function() {
                if (!AlipayPaymentPolling.timer) {
                    AlipayPaymentPolling.startPolling();
                }
            });

            // Add manual check button
            this.addManualCheckButton();
        },

        /**
         * Get order ID from various sources
         */
        getOrderId: function() {
            // Try to get from data attribute
            var orderId = $('body').data('order-id');
            
            if (orderId) {
                return orderId;
            }

            // Try to get from URL parameter
            var urlParams = new URLSearchParams(window.location.search);
            orderId = urlParams.get('order_id');
            
            if (orderId) {
                return orderId;
            }

            // Try to get from order-pay URL structure
            var pathMatch = window.location.pathname.match(/order-pay\/(\d+)\//);
            if (pathMatch && pathMatch[1]) {
                return pathMatch[1];
            }

            return null;
        },

        /**
         * Start polling
         */
        startPolling: function() {
            if (this.timer) {
                return; // Already polling
            }

            this.currentAttempt = 0;
            this.poll();
        },

        /**
         * Stop polling
         */
        stopPolling: function() {
            if (this.timer) {
                clearTimeout(this.timer);
                this.timer = null;
            }
        },

        /**
         * Poll order status
         */
        poll: function() {
            var self = this;

            if (this.currentAttempt >= this.maxAttempts) {
                this.stopPolling();
                this.showMessage('warning', woo_alipay_polling.strings.timeout);
                return;
            }

            this.currentAttempt++;

            $.ajax({
                url: woo_alipay_polling.ajax_url,
                type: 'POST',
                data: {
                    action: 'woo_alipay_query_order_status',
                    order_id: this.orderId,
                    nonce: woo_alipay_polling.nonce
                },
                success: function(response) {
                    if (response.success) {
                        if (response.data.status === 'paid' || response.data.redirect) {
                            // Payment confirmed
                            self.stopPolling();
                            self.showMessage('success', response.data.message);
                            
                            // Redirect after a short delay
                            setTimeout(function() {
                                window.location.href = response.data.redirect;
                            }, 1000);
                        } else if (response.data.status === 'pending') {
                            // Still pending, continue polling
                            self.timer = setTimeout(function() {
                                self.poll();
                            }, self.pollInterval);
                        }
                    } else {
                        // Error occurred, but continue polling
                        console.log('Polling error:', response.data.message);
                        self.timer = setTimeout(function() {
                            self.poll();
                        }, self.pollInterval);
                    }
                },
                error: function(xhr, status, error) {
                    console.log('AJAX error:', error);
                    // Continue polling on error
                    self.timer = setTimeout(function() {
                        self.poll();
                    }, self.pollInterval);
                }
            });
        },

        /**
         * Show message to user
         */
        showMessage: function(type, message) {
            var messageClass = 'woocommerce-message';
            
            if (type === 'error') {
                messageClass = 'woocommerce-error';
            } else if (type === 'warning') {
                messageClass = 'woocommerce-info';
            }

            var $message = $('<div class="' + messageClass + '">' + message + '</div>');
            
            // Remove existing messages
            $('.woocommerce-message, .woocommerce-error, .woocommerce-info').remove();
            
            // Add new message
            $('.woocommerce').prepend($message);
            
            // Scroll to message
            $('html, body').animate({
                scrollTop: $message.offset().top - 100
            }, 500);
        },

        /**
         * Add manual check button
         */
        addManualCheckButton: function() {
            var self = this;
            
            // Find a good place to add the button
            var $container = $('.wooalipay-loader, .woocommerce-order-pay');
            
            if ($container.length === 0) {
                return;
            }

            var $button = $('<button type="button" class="button woo-alipay-check-status" style="margin-top: 20px;">' + 
                          woo_alipay_polling.strings.check_status + '</button>');
            
            $container.append($button);

            $button.on('click', function(e) {
                e.preventDefault();
                
                $button.prop('disabled', true).text(woo_alipay_polling.strings.checking);
                
                $.ajax({
                    url: woo_alipay_polling.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'woo_alipay_query_order_status',
                        order_id: self.orderId,
                        nonce: woo_alipay_polling.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            if (response.data.status === 'paid' || response.data.redirect) {
                                self.showMessage('success', response.data.message);
                                
                                setTimeout(function() {
                                    window.location.href = response.data.redirect;
                                }, 1000);
                            } else {
                                self.showMessage('info', response.data.message);
                                $button.prop('disabled', false).text(woo_alipay_polling.strings.check_status);
                            }
                        } else {
                            self.showMessage('error', response.data.message);
                            $button.prop('disabled', false).text(woo_alipay_polling.strings.check_status);
                        }
                    },
                    error: function() {
                        self.showMessage('error', woo_alipay_polling.strings.error);
                        $button.prop('disabled', false).text(woo_alipay_polling.strings.check_status);
                    }
                });
            });
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        if (typeof woo_alipay_polling !== 'undefined') {
            AlipayPaymentPolling.init();
        }
    });

})(jQuery);
