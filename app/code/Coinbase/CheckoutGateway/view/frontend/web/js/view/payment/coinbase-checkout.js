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
            template: 'Coinbase_CheckoutGateway/payment/coinbase-checkout',
            redirectAfterPlaceOrder: false
        },

        getCode: function () {
            return 'coinbase_checkout';
        },

        isActive: function () {
            return window.checkoutConfig.payment.coinbase_checkout.isActive;
        },

        getTitle: function () {
            return window.checkoutConfig.payment.coinbase_checkout.title;
        },

        getLogoUrl: function () {
            return window.checkoutConfig.payment.coinbase_checkout.logoUrl;
        },

        getDescription: function () {
            return window.checkoutConfig.payment.coinbase_checkout.description;
        },

        getRedirectUrl: function () {
            return window.checkoutConfig.payment.coinbase_checkout.redirectUrl;
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
