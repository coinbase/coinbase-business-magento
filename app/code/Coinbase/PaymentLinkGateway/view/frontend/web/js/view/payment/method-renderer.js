define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'coinbase_payment_link',
        component: 'Coinbase_PaymentLinkGateway/js/view/payment/coinbase-payment-link'
    });

    return Component.extend({});
});
