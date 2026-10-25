define([
    'Magento_Checkout/js/view/payment/default',
    'mage/url',
    'require'
], function (Component, url, require) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Coldtill_Payment/payment/coldtill',
            redirectAfterPlaceOrder: false
        },

        getLogoUrl: function () {
            return require.toUrl('Coldtill_Payment/images/logo.png');
        },

        // The order exists now; the redirect controller creates the Coldtill payment and sends the customer on.
        afterPlaceOrder: function () {
            window.location.replace(url.build('coldtill/payment/redirect'));
        }
    });
});
