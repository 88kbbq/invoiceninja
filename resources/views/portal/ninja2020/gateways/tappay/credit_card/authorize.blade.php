@extends('portal.ninja2020.layout.app')

@section('meta_title', ctrans('texts.add_payment_method'))

@section('body')
    <div class="container mx-auto">
        <div class="grid grid-cols-6 gap-4">
            <div class="col-span-6 md:col-start-2 md:col-span-4">
                <div class="flex justify-center mb-8">
                    <h1 class="text-2xl">{{ ctrans('texts.add_payment_method') }}</h1>
                </div>

                <meta name="app-id" content="{{ $app_id }}">
                <meta name="app-key" content="{{ $app_key }}">
                <meta name="server-type" content="{{ $server_type }}">

                <script src="https://js.tappaysdk.com/sdk/tpdirect/v5.14.0"></script>

                <style>
                    .tpfield {
                        height: 40px;
                        width: 100%;
                        border: 1px solid #e2e8f0;
                        border-radius: 0.375rem;
                        padding: 0.5rem 0.75rem;
                        font-size: 0.875rem;
                        transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
                        background-color: white;
                    }

                    .tpfield:focus {
                        outline: none;
                        border-color: #3b82f6;
                        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
                    }

                    .tpfield.has-error {
                        border-color: #ef4444;
                    }

                    .field-error {
                        color: #ef4444;
                        font-size: 0.75rem;
                        margin-top: 0.25rem;
                    }

                    #authorize-button:disabled {
                        opacity: 0.5;
                        cursor: not-allowed;
                    }
                </style>

                <form action="{{ route('client.payment_methods.store', ['method' => App\Models\GatewayType::CREDIT_CARD]) }}" method="post" id="authorize-form">
                    @csrf
                    <input type="hidden" name="prime" id="prime-input">
                    <input type="hidden" name="cardholder_name" id="cardholder-name-input">
                    <input type="hidden" name="company_gateway_id" value="{{ $gateway->company_gateway->id }}">
                    <input type="hidden" name="payment_method_id" value="{{ App\Models\GatewayType::CREDIT_CARD }}">
                </form>

                <div class="bg-white shadow rounded px-6 py-4 mb-4">
                    <h3 class="text-lg font-medium mb-4">{{ ctrans('texts.credit_card') }} (TapPay)</h3>

                    <!-- Cardholder Name -->
                    <div class="mb-4">
                        <label for="cardholder-name" class="block text-sm font-medium text-gray-700 mb-1">
                            {{ ctrans('texts.cardholder_name') }}
                        </label>
                        <input
                            type="text"
                            id="cardholder-name"
                            name="cardholder_name"
                            class="input w-full"
                            style="height: 40px; width: 100%; border: 1px solid #e2e8f0; border-radius: 0.375rem; padding: 0.5rem 0.75rem; font-size: 0.875rem;"
                            placeholder="{{ $cardholder_name }}"
                            value="{{ $cardholder_name }}"
                            required
                            autocomplete="cc-name">
                    </div>

                    <!-- Card Number (TapPay Field) -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            {{ ctrans('texts.card_number') }}
                        </label>
                        <div class="tpfield" id="tappay-card-number"></div>
                        <div id="card-number-error" class="field-error" style="display: none;"></div>
                    </div>

                    <!-- Expiration Date (TapPay Field) -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            {{ ctrans('texts.expiration_date') }}
                        </label>
                        <div class="tpfield" id="tappay-card-expiry"></div>
                        <div id="card-expiry-error" class="field-error" style="display: none;"></div>
                    </div>

                    <!-- CVV (TapPay Field) -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            {{ ctrans('texts.cvv') }}
                        </label>
                        <div class="tpfield" id="tappay-card-cvc"></div>
                        <div id="card-cvc-error" class="field-error" style="display: none;"></div>
                    </div>

                    <!-- Authorize Button -->
                    <button
                        type="button"
                        id="authorize-button"
                        class="button button--primary button--block"
                        style="width: 100%; margin-top: 1rem;"
                        disabled>
                        {{ ctrans('texts.add_payment_method') }}
                    </button>
                </div>

                <div class="flex justify-center">
                    <a href="{{ route('client.payment_methods.index') }}" class="button button--secondary">
                        {{ ctrans('texts.back') }}
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const appId = document.querySelector('meta[name="app-id"]').content;
            const appKey = document.querySelector('meta[name="app-key"]').content;
            const serverType = document.querySelector('meta[name="server-type"]').content;
            let canGetPrime = false;

            // Initialize TapPay SDK
            TPDirect.setupSDK(appId, appKey, serverType);

            // Setup card fields
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
            TPDirect.card.onUpdate(function(update) {
                canGetPrime = update.canGetPrime;
                const cardholderName = document.getElementById('cardholder-name').value.trim();
                const authorizeButton = document.getElementById('authorize-button');

                authorizeButton.disabled = !(canGetPrime && cardholderName);

                // Update field errors
                updateFieldError('number', update.status.number, 'card-number-error');
                updateFieldError('expiry', update.status.expiry, 'card-expiry-error');
                updateFieldError('ccv', update.status.ccv, 'card-cvc-error');

                // Update field styling
                updateFieldStyle('tappay-card-number', update.status.number);
                updateFieldStyle('tappay-card-expiry', update.status.expiry);
                updateFieldStyle('tappay-card-cvc', update.status.ccv);
            });

            // Cardholder name validation
            document.getElementById('cardholder-name').addEventListener('input', function() {
                const authorizeButton = document.getElementById('authorize-button');
                authorizeButton.disabled = !(canGetPrime && this.value.trim());
            });

            // Authorize button click
            document.getElementById('authorize-button').addEventListener('click', function() {
                if (!canGetPrime) {
                    alert('Please complete all card fields');
                    return;
                }

                const cardholderName = document.getElementById('cardholder-name').value.trim();
                if (!cardholderName) {
                    alert('Please enter cardholder name');
                    return;
                }

                this.disabled = true;
                this.textContent = 'Processing...';

                TPDirect.card.getPrime(function(result) {
                    if (result.status !== 0) {
                        alert('Card validation failed: ' + result.msg);
                        document.getElementById('authorize-button').disabled = false;
                        document.getElementById('authorize-button').textContent = '{{ ctrans('texts.add_payment_method') }}';
                        return;
                    }

                    document.getElementById('prime-input').value = result.card.prime;
                    document.getElementById('cardholder-name-input').value = cardholderName;
                    document.getElementById('authorize-form').submit();
                });
            });

            function updateFieldError(fieldName, status, errorElementId) {
                const errorElement = document.getElementById(errorElementId);
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

            function updateFieldStyle(fieldId, status) {
                const field = document.getElementById(fieldId);
                if (!field) return;

                field.classList.remove('has-error');
                if (status === 2) {
                    field.classList.add('has-error');
                }
            }
        });
    </script>
@endsection
