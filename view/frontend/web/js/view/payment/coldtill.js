define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'coldtill',
        component: 'Coldtill_Payment/js/view/payment/method-renderer/coldtill'
    });

    return Component.extend({});
});
