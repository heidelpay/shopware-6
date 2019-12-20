import Plugin from 'src/plugin-system/plugin.class';

export default class HeidelpaySepaDirectDebitPlugin extends Plugin {
    static options = {
        acceptMandateId: 'acceptSepaMandate',
        mandateNotAcceptedError: 'Please accept the SEPA direct debit mandate in order to continue.',
    };

    /**
     * @type {Object}
     *
     * @public
     */
    static sepa;

    /**
     * @type {HeidelpayBasePlugin}
     *
     * @private
     */
    static heidelpayPlugin = null;

    init() {
        this.heidelpayPlugin = window.PluginManager.getPluginInstances('HeidelpayBase')[0];
        this.sepa = this.heidelpayPlugin.heidelpayInstance.SepaDirectDebit();

        this._createForm();
        this._registerEvents();
    }

    _createForm() {
        this.sepa.create('sepa-direct-debit', {
            containerId: 'heidelpay-sepa-container',
        });
    }

    _registerEvents() {
        this.heidelpayPlugin.$emitter.subscribe('heidelpayBase_createResource', () => this._onCreateResource(), {
            scope: this,
        });
    }

    _onCreateResource() {
        const mandateAcceptedCheckbox = document.getElementById(this.options.acceptMandateId);

        if (!mandateAcceptedCheckbox.checked) {
            this._handleError({
                'message': this.options.mandateNotAcceptedError,
            });

            mandateAcceptedCheckbox.classList.add('is-invalid');

            return;
        }

        this.heidelpayPlugin.setSubmitButtonActive(false);

        this.sepa.createResource()
            .then((resource) => this._submitPayment(resource))
            .catch((error) => this._handleError(error));
    }

    /**
     * @param {Object} resource
     * @private
     */
    _submitPayment(resource) {
        this.heidelpayPlugin.submitResource(resource);
    }

    /**
     * @param {Object} error
     *
     * @private
     */
    _handleError(error) {
        this.heidelpayPlugin.showError(error);
    }
}