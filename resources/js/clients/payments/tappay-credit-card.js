/**
 * TapPay Credit Card Payment Integration
 *
 * Handles TapPay SDK initialization and payment processing
 * for Invoice Ninja payment gateway
 */

import { wait, instant } from '../wait';

class TapPayCreditCardPayment {
    constructor() {
        this.canGetPrime = false;
        this.appId = null;
        this.appKey = null;
        this.serverType = null;
        this.elements = {};
    }

    /**
     * Setup TapPay SDK and event listeners
     */
    setup() {
        // Cache DOM elements early so we can surface configuration errors in the UI
        this.cacheElements();

        // Get configuration from meta tags
        this.appId = this.getConfigValue('app-id');
        this.appKey = this.getConfigValue('app-key');
        this.serverType = this.getConfigValue('server-type');
        console.log('TapPay meta', { appId: this.appId, appKey: this.appKey, serverType: this.serverType });
        console.log('TapPay meta values', this.appId, this.appKey, this.serverType);

        const sdkScript = this.ensureSdkScript();

        if (!sdkScript) {
            console.error('Unable to attach TapPay SDK script');
            this.showConfigurationError();
            return;
        }

        sdkScript.addEventListener('load', () => {
            console.log('TapPay SDK script load event', typeof window.TPDirect);
        });

        sdkScript.addEventListener('error', (event) => {
            console.error('TapPay SDK script error event', event);
            this.showConfigurationError();
        });

        if (!this.appId || !this.appKey || !this.serverType) {
            console.error('TapPay configuration missing');

            this.showConfigurationError();
            return;
        }

        this.waitForSDK()
            .then(() => {
                this.initializeTapPay();
                this.attachEventListeners();
            })
            .catch(() => {
                console.error('TapPay SDK failed to load');
                this.showConfigurationError();
            });
    }

    /**
     * Get configuration value from data attributes or meta tags
     */
    getConfigValue(name) {
        const container = document.getElementById('tappay-credit-card-payment');
        if (container) {
            const datasetKey = name.replace(/-([a-z])/g, (_, char) => char.toUpperCase());
            const value = container.dataset[datasetKey];
            if (value) {
                return value;
            }
        }

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
            payNowButton: document.getElementById('pay-now'),
            serverResponseForm: document.getElementById('server-response'),
            tappayContainer: document.getElementById('tappay--payment-container'),
            saveCardContainer: document.getElementById('save-card--container'),
            tokenInput: document.querySelector('input[name="token"]'),
            storeCardInput: document.querySelector('input[name="store_card"]'),
            saveCardCheckbox: document.querySelector('input[name="token-billing-checkbox"]'),
            toggleTokenButtons: document.querySelectorAll('.toggle-payment-with-token'),
            toggleNewCardButton: document.getElementById('toggle-payment-with-credit-card'),
            errorsDiv: document.getElementById('errors'),
            errorElements: {
                cardNumber: document.getElementById('card-number-error'),
                cardExpiry: document.getElementById('card-expiry-error'),
                cardCvc: document.getElementById('card-cvc-error')
            }
        };
    }

    ensureSdkScript() {
        const container = document.getElementById('tappay-credit-card-payment');
        const defaultSrc = 'https://js.tappaysdk.com/sdk/tpdirect/v5.19.2';
        const sdkSrc = container?.dataset.sdkSrc || defaultSrc;

        let script = document.querySelector(`script[src="${sdkSrc}"]`);

        if (!script) {
            console.warn('TapPay SDK script tag not found in DOM, creating dynamically');
            script = document.createElement('script');
            script.src = sdkSrc;
            script.async = true;
            document.head.appendChild(script);
        }

        return script;
    }

    /**
     * Initialize TapPay SDK
     */
    initializeTapPay() {
        // Setup SDK
        const sdk = window.TPDirect;

        sdk.setupSDK(this.appId, this.appKey, this.serverType);

        // Configure card fields
        sdk.card.setup({
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
                    'font-size': '14px',
                    'font-family': "'Open Sans', 'Helvetica Neue', Arial, sans-serif",
                    'line-height': '1.5rem',
                    'letter-spacing': '0.02em',
                    'background-color': 'transparent'
                },
                ':focus': {
                    'color': '#1f2937'
                },
                'input::placeholder': {
                    'color': '#9ca3af'
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
        sdk.card.onUpdate((update) => this.handleCardUpdate(update));
    }

    /**
     * Attach all event listeners
     */
    attachEventListeners() {
        // Payment type toggle - use tokens
        this.elements.toggleTokenButtons.forEach(button => {
            button.addEventListener('click', () => {
                this.elements.tappayContainer.classList.add('hidden');
                if (this.elements.saveCardContainer) {
                    this.elements.saveCardContainer.style.display = 'none';
                }
                this.elements.tokenInput.value = button.dataset.token;
            });
        });

        // Payment type toggle - use new card
        if (this.elements.toggleNewCardButton) {
            this.elements.toggleNewCardButton.addEventListener('click', () => {
                this.elements.tappayContainer.classList.remove('hidden');
                if (this.elements.saveCardContainer) {
                    this.elements.saveCardContainer.style.display = 'grid';
                }
                this.elements.tokenInput.value = '';
            });
        }

        // Pay now button
        if (this.elements.payNowButton) {
            this.elements.payNowButton.addEventListener('click', () => {
                try {
                    // Check if using saved token
                    if (this.elements.tokenInput.value) {
                        return this.completePaymentUsingToken();
                    }

                    // Otherwise use new card
                    return this.completePaymentWithoutToken();
                } catch (error) {
                    console.error('Payment error:', error);
                    this.handleFailure(error.message);
                }
            });
        }
    }

    /**
     * Handle TapPay card field updates
     */
    handleCardUpdate(update) {
        this.canGetPrime = update.canGetPrime;

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
     * Complete payment using saved token
     */
    completePaymentUsingToken() {
        this.showProcessing();
        this.submitForm();
    }

    /**
     * Complete payment without token (new card)
     */
    completePaymentWithoutToken() {
        if (!this.canGetPrime) {
            return this.handleFailure('Please complete all card fields');
        }

        const cardholderName = this.elements.cardholderName.value.trim();
        if (!cardholderName) {
            return this.handleFailure('Please enter cardholder name');
        }

        this.showProcessing();

        // Get prime token from TapPay
        const sdk = window.TPDirect;

        sdk.card.getPrime((result) => {
            if (result.status !== 0) {
                return this.handleFailure('Card validation failed: ' + result.msg);
            }

            // Set hidden form fields
            this.elements.primeInput.value = result.card.prime;
            this.elements.cardholderNameInput.value = cardholderName;

            // Handle save card checkbox
            if (this.elements.saveCardCheckbox && this.elements.saveCardCheckbox.checked) {
                this.elements.storeCardInput.value = this.elements.saveCardCheckbox.value;
            }

            // Submit form
            this.submitForm();
        });
    }

    /**
     * Show processing state on pay button
     */
    showProcessing() {
        if (!this.elements.payNowButton) return;

        this.elements.payNowButton.disabled = true;
        this.elements.payNowButton.querySelector('svg').classList.remove('hidden');
        this.elements.payNowButton.querySelector('span').classList.add('hidden');
    }

    /**
     * Hide processing state on pay button
     */
    showConfigurationError() {
        if (this.elements.errorsDiv) {
            this.elements.errorsDiv.textContent = 'Payment configuration is incomplete. Please contact the merchant.';
            this.elements.errorsDiv.hidden = false;
        }

        if (this.elements.tappayContainer) {
            this.elements.tappayContainer.classList.add('hidden');
        }

        if (this.elements.payNowButton) {
            this.elements.payNowButton.setAttribute('disabled', 'disabled');
        }
    }

    waitForSDK() {
        return new Promise((resolve, reject) => {
            const maxAttempts = 40;
            const interval = 100;
            let attempts = 0;

            const check = () => {
                if (window.TPDirect) {
                    resolve();
                    return;
                }

                attempts += 1;
                if (attempts > maxAttempts) {
                    reject();
                    return;
                }

                setTimeout(check, interval);
            };

            check();
        });
    }

    hideProcessing() {
        if (!this.elements.payNowButton) return;

        this.elements.payNowButton.disabled = false;
        this.elements.payNowButton.querySelector('svg').classList.add('hidden');
        this.elements.payNowButton.querySelector('span').classList.remove('hidden');
    }

    /**
     * Handle payment failure
     */
    handleFailure(message) {
        if (this.elements.errorsDiv) {
            this.elements.errorsDiv.textContent = message;
            this.elements.errorsDiv.hidden = false;
        }

        this.hideProcessing();
    }

    /**
     * Submit the payment form
     */
    submitForm() {
        if (this.elements.serverResponseForm) {
            this.elements.serverResponseForm.submit();
        }
    }

    /**
     * Initialize and handle the payment flow
     */
    handle() {
        this.setup();

        // If there are saved tokens, click the first one by default
        const tokens = document.querySelectorAll('input.toggle-payment-with-token');
        if (tokens.length > 0) {
            tokens[0].click();
        }
    }
}

/**
 * Bootstrap the payment form
 */
function boot() {
    const tappay = new TapPayCreditCardPayment();
    tappay.handle();
}

// Initialize when DOM is ready
instant() ? boot() : wait('#tappay--payment-container').then(() => boot());

// Export for potential reuse
export default TapPayCreditCardPayment;
