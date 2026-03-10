define([
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/model/full-screen-loader',
    'Magento_Customer/js/customer-data',
    'jquery',
    'mage/url'
], function (Component, fullScreenLoader, customerData, $, urlBuilder) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Coinbase_PaymentLinkGateway/payment/coinbase-payment-link',
            redirectAfterPlaceOrder: false
        },

        getCode: function () {
            return 'coinbase_payment_link';
        },

        isActive: function () {
            return window.checkoutConfig.payment.coinbase_payment_link.isActive;
        },

        getTitle: function () {
            return window.checkoutConfig.payment.coinbase_payment_link.title;
        },

        getLogoUrl: function () {
            return window.checkoutConfig.payment.coinbase_payment_link.logoUrl;
        },

        getDescription: function () {
            return window.checkoutConfig.payment.coinbase_payment_link.description;
        },

        getRedirectUrl: function () {
            return window.checkoutConfig.payment.coinbase_payment_link.redirectUrl;
        },

        afterPlaceOrder: function () {
            var self = this;
            fullScreenLoader.startLoader();

            $.ajax({
                url: self.getRedirectUrl(),
                type: 'POST',
                dataType: 'json',
                data: {},
                success: function (response) {
                    if (response.success && response.redirect_url) {
                        // Invalidate cart data before redirect
                        customerData.invalidate(['cart']);
                        window.location.href = response.redirect_url;
                    } else {
                        fullScreenLoader.stopLoader();
                        self.messageContainer.addErrorMessage({
                            message: response.message || 'Unable to redirect to Coinbase. Please try again.'
                        });
                    }
                },
                error: function () {
                    fullScreenLoader.stopLoader();
                    self.messageContainer.addErrorMessage({
                        message: 'An error occurred while connecting to Coinbase. Please try again.'
                    });
                }
            });
        }
    });
});
