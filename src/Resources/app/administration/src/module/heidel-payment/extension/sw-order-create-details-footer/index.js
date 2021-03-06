import template from './sw-order-create-details-footer.html.twig';

const { Component, Context } = Shopware;
const { Criteria } = Shopware.Data;
const heidelPaymentHandler = 'HeidelPayment6\\Components';

Component.override('sw-order-create-details-footer', {
    template,

    computed: {
        paymentMethodCriteria() {
            /** @var {Criteria} paymentCriteria */
            const criteria = new Criteria();

            if (this.salesChannelId) {
                criteria.addFilter(Criteria.equals('salesChannels.id', this.salesChannelId));
            }

            criteria.addFilter(
                Criteria.not('AND', [
                    Criteria.contains('handlerIdentifier', heidelPaymentHandler)
                ])
            );

            return criteria;
        }
    }
});
