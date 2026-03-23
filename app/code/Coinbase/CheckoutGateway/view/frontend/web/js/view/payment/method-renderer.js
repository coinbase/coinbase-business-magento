define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'coinbase_checkout',
        component: 'Coinbase_CheckoutGateway/js/view/payment/coinbase-checkout'
    });

    return Component.extend({});
});
