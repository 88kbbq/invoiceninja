/**
 * TapPay Credit Card Payment Integration
 *
 * Handles TapPay SDK initialization and payment processing
 * for Invoice Ninja payment gateway
 */

class TapPayCreditCardPayment {
    constructor() {
        this.canGetPrime = false;
        this.appId = null;
        this.appKey = null;
        this.serverType = null;
        this.elements = {};
    }

    /**
     * Initialize the payment form
     */
    init() {
        // Wait for DOM to be ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.setup());
        } else {
            this.setup();
        }
    }

    /**
     * Setup TapPay SDK and event handlers
     */
    setup() {
        // Get configuration from meta tags
        this.appId = this.getMetaContent('app-id');
        this.appKey = this.getMetaContent('app-key');
        this.serverType = this.getMetaContent('server-type');

        if (!this.appId || !this.appKey || !this.serverType) {
            console.error('TapPay configuration missing');
            return;
        }

        // Cache DOM elements
        this.cacheElements();

        // Initialize TapPay SDK
        this.initializeTapPay();

        // Setup event listeners
        this.attachEventListeners();
    }

    /**
     * Get content from meta tag
     */
    getMetaContent(name) {
        const meta = document.querySelector(`meta[name="${name}"]`);
        return meta ? meta.content : null;
    }

    /**
     * Cache frequently used DOM elements
     */
    cacheElements() {
        this.elements = {
            cardholderName: document.getElementById('cardholder-name'),
            cardholderNameInput: document.getElementById('cardholder-name-input'),
            primeInput: document.getElementById('prime-input'),
            storeCardInput: document.getElementById('store-card-input'),
            payButton: document.getElementById('pay-button'),
            serverResponseForm: document.getElementById('server-response'),
            tappayContainer: document.getElementById('tappay-container'),
            tokenContainer: document.getElementById('pay-now-with-token--container'),
            tokenInput: document.querySelector('input[name="token"]'),
            saveCardCheckbox: document.querySelector('input[name="store_card"]'),
            toggleTokenButtons: document.querySelectorAll('.toggle-payment-with-token'),
            toggleNewCardButton: document.getElementById('toggle-payment-with-credit-card'),
            payNowTokenButton: document.getElementById('pay-now-with-token'),
            errorElements: {
                cardNumber: document.getElementById('card-number-error'),
                cardExpiry: document.getElementById('card-expiry-error'),
                cardCvc: document.getElementById('card-cvc-error')
            }
        };
    }

    /**
     * Initialize TapPay SDK
     */
    initializeTapPay() {
        // Setup SDK
        TPDirect.setupSDK(this.appId, this.appKey, this.serverType);

        // Configure card fields
        TPDirect.card.setup({
            fields: {
                number: {
                    element: '#tappay-card-number',
                    placeholder: '•••• •••• •••• ••••'
                },
                expirationDate: {
                    element: '#tappay-card-expiry',
                    placeholder: 'MM / YY'
                },
                ccv: {
                    element: '#tappay-card-cvc',
                    placeholder: '•••'
                }
            },
            styles: {
                'input': {
                    'color': '#1f2937',
                    'font-size': '14px'
                },
                ':focus': {
                    'color': '#1f2937'
                },
                '.valid': {
                    'color': '#059669'
                },
                '.invalid': {
                    'color': '#ef4444'
                }
            },
            isMaskCreditCardNumber: true,
            maskCreditCardNumberRange: {
                beginIndex: 6,
                endIndex: 11
            }
        });

        // Listen for field updates
        TPDirect.card.onUpdate((update) => this.handleCardUpdate(update));
    }

    /**
     * Attach all event listeners
     */
    attachEventListeners() {
        // Cardholder name input
        if (this.elements.cardholderName) {
            this.elements.cardholderName.addEventListener('input', () => {
                this.updatePayButtonState();
            });
        }

        // Pay button
        if (this.elements.payButton) {
            this.elements.payButton.addEventListener('click', () => {
                this.handlePayButtonClick();
            });
        }

        // Payment type toggles
        this.elements.toggleTokenButtons.forEach(button => {
            button.addEventListener('change', (e) => {
                this.handleTokenSelection(e.target);
            });
        });

        if (this.elements.toggleNewCardButton) {
            this.elements.toggleNewCardButton.addEventListener('change', () => {
                this.handleNewCardSelection();
            });
        }

        // Save card checkbox
        if (this.elements.saveCardCheckbox) {
            this.elements.saveCardCheckbox.addEventListener('change', (e) => {
                this.elements.storeCardInput.value = e.target.checked ? '1' : '0';
            });
        }

        // Pay now with token button
        if (this.elements.payNowTokenButton) {
            this.elements.payNowTokenButton.addEventListener('click', (e) => {
                e.preventDefault();
                this.submitForm();
            });
        }
    }

    /**
     * Handle TapPay card field updates
     */
    handleCardUpdate(update) {
        this.canGetPrime = update.canGetPrime;

        // Update pay button state
        this.updatePayButtonState();

        // Update field errors
        this.updateFieldError('number', update.status.number, 'cardNumber');
        this.updateFieldError('expiry', update.status.expiry, 'cardExpiry');
        this.updateFieldError('ccv', update.status.ccv, 'cardCvc');

        // Update field styling
        this.updateFieldStyle('tappay-card-number', update.status.number);
        this.updateFieldStyle('tappay-card-expiry', update.status.expiry);
        this.updateFieldStyle('tappay-card-cvc', update.status.ccv);
    }

    /**
     * Update pay button enabled/disabled state
     */
    updatePayButtonState() {
        if (!this.elements.payButton || !this.elements.cardholderName) return;

        const cardholderName = this.elements.cardholderName.value.trim();
        this.elements.payButton.disabled = !(this.canGetPrime && cardholderName);
    }

    /**
     * Update field error display
     */
    updateFieldError(fieldName, status, errorKey) {
        const errorElement = this.elements.errorElements[errorKey];
        if (!errorElement) return;

        const messages = {
            'number': 'Invalid card number',
            'expiry': 'Invalid expiration date',
            'ccv': 'Invalid CVV'
        };

        if (status === 2) {
            errorElement.textContent = messages[fieldName];
            errorElement.style.display = 'block';
        } else {
            errorElement.style.display = 'none';
        }
    }

    /**
     * Update field styling based on validation status
     */
    updateFieldStyle(fieldId, status) {
        const field = document.getElementById(fieldId);
        if (!field) return;

        field.classList.remove('has-error');
        if (status === 2) {
            field.classList.add('has-error');
        }
    }

    /**
     * Handle pay button click (new card payment)
     */
    handlePayButtonClick() {
        if (!this.canGetPrime) {
            alert('Please complete all card fields');
            return;
        }

        const cardholderName = this.elements.cardholderName.value.trim();
        if (!cardholderName) {
            alert('Please enter cardholder name');
            return;
        }

        // Disable button and show processing state
        this.elements.payButton.disabled = true;
        const originalText = this.elements.payButton.textContent;
        this.elements.payButton.textContent = 'Processing...';

        // Get prime token from TapPay
        TPDirect.card.getPrime((result) => {
            if (result.status !== 0) {
                alert('Card validation failed: ' + result.msg);
                this.elements.payButton.disabled = false;
                this.elements.payButton.textContent = originalText;
                return;
            }

            // Set hidden form fields
            this.elements.primeInput.value = result.card.prime;
            this.elements.cardholderNameInput.value = cardholderName;

            if (this.elements.saveCardCheckbox) {
                this.elements.storeCardInput.value = this.elements.saveCardCheckbox.checked ? '1' : '0';
            }

            // Submit form
            this.submitForm();
        });
    }

    /**
     * Handle token payment selection
     */
    handleTokenSelection(button) {
        if (button.checked) {
            this.elements.tappayContainer.classList.add('hidden');
            this.elements.tokenContainer.classList.remove('hidden');
            this.elements.tokenInput.value = button.dataset.token;
        }
    }

    /**
     * Handle new card selection
     */
    handleNewCardSelection() {
        this.elements.tappayContainer.classList.remove('hidden');
        this.elements.tokenContainer.classList.add('hidden');
        this.elements.tokenInput.value = '';
    }

    /**
     * Submit the payment form
     */
    submitForm() {
        if (this.elements.serverResponseForm) {
            this.elements.serverResponseForm.submit();
        }
    }
}

// Initialize payment form
const tapPayPayment = new TapPayCreditCardPayment();
tapPayPayment.init();

// Export for potential reuse
export default TapPayCreditCardPayment;
