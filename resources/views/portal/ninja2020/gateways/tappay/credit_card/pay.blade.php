@extends('portal.ninja2020.layout.payments', ['gateway_title' => 'Credit card', 'card_title' => 'Credit card'])

@section('gateway_head')
    <meta name="app-id" content="{{ $app_id }}">
    <meta name="app-key" content="{{ $app_key }}">
    <meta name="server-type" content="{{ $server_type }}">
    <meta name="value" content="{{ $value }}">
    <meta name="currency" content="{{ $currency }}">
    <meta name="payment-hash" content="{{ $payment_hash }}">

    <script src="https://js.tappaysdk.com/sdk/tpdirect/v5.14.0"></script>

    <style>
        /* Fix duplicate sidebar issue - force hide mobile sidebar on payment pages */
        .main_layout .md\:hidden {
            display: none !important;
        }

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

        #pay-button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .input-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            color: #374151;
            margin-bottom: 0.25rem;
        }
    </style>
@endsection

@section('gateway_content')
    <form action="{{ route('client.payments.response') }}" method="post" id="server-response">
        @csrf
        <input type="hidden" name="prime" id="prime-input">
        <input type="hidden" name="cardholder_name" id="cardholder-name-input">
        <input type="hidden" name="store_card" id="store-card-input">
        <input type="hidden" name="payment_hash" value="{{ $payment_hash }}">
        <input type="hidden" name="company_gateway_id" value="{{ $company_gateway->id }}">
        <input type="hidden" name="payment_method_id" value="{{ $payment_method_id }}">
        <input type="hidden" name="value" value="{{ $value }}">
        <input type="hidden" name="raw_value" value="{{ $raw_value }}">
        <input type="hidden" name="currency" value="{{ $currency }}">
        <input type="hidden" name="token" value="">
    </form>

    @component('portal.ninja2020.components.general.card-element', ['title' => ctrans('texts.payment_type')])
        {{ ctrans('texts.credit_card') }} (TapPay)
    @endcomponent

    @include('portal.ninja2020.gateways.includes.payment_details')

    @component('portal.ninja2020.components.general.card-element', ['title' => ctrans('texts.pay_with')])
        @if(count($tokens) > 0)
            @foreach($tokens as $token)
                <label class="mr-4">
                    <input
                        type="radio"
                        data-token="{{ $token->hashed_id }}"
                        name="payment-type"
                        class="form-radio cursor-pointer toggle-payment-with-token"/>
                    <span class="ml-1 cursor-pointer">**** {{ $token->meta?->last4 }}</span>
                </label>
            @endforeach
        @endif

        <label>
            <input
                type="radio"
                id="toggle-payment-with-credit-card"
                class="form-radio cursor-pointer"
                name="payment-type"
                checked/>
            <span class="ml-1 cursor-pointer">{{ __('texts.new_card') }}</span>
        </label>
    @endcomponent

    @include('portal.ninja2020.gateways.includes.save_card')

    @component('portal.ninja2020.components.general.card-element-single')
        <div id="tappay-container">
            <!-- Cardholder Name -->
            <div class="mb-4">
                <label for="cardholder-name" class="input-label">
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
                <label class="input-label">
                    {{ ctrans('texts.card_number') }}
                </label>
                <div class="tpfield" id="tappay-card-number"></div>
                <div id="card-number-error" class="field-error" style="display: none;"></div>
            </div>

            <!-- Expiration Date (TapPay Field) -->
            <div class="mb-4">
                <label class="input-label">
                    {{ ctrans('texts.expiration_date') }}
                </label>
                <div class="tpfield" id="tappay-card-expiry"></div>
                <div id="card-expiry-error" class="field-error" style="display: none;"></div>
            </div>

            <!-- CVV (TapPay Field) -->
            <div class="mb-4">
                <label class="input-label">
                    {{ ctrans('texts.cvv') }}
                </label>
                <div class="tpfield" id="tappay-card-cvc"></div>
                <div id="card-cvc-error" class="field-error" style="display: none;"></div>
            </div>

            <!-- Pay Button -->
            <button
                type="button"
                id="pay-button"
                class="button button--primary button--block"
                style="width: 100%; margin-top: 1rem;"
                disabled>
                {{ ctrans('texts.pay') }} {{ App\Utils\Number::formatMoney($total['amount_with_fee'], $client) }}
            </button>
        </div>
    @endcomponent

    @component('portal.ninja2020.components.general.card-element-single')
        <div class="hidden" id="pay-now-with-token--container">
            @include('portal.ninja2020.gateways.includes.pay_now', ['id' => 'pay-now-with-token'])
        </div>
    @endcomponent
@endsection

@section('gateway_footer')
    @vite('resources/js/clients/payments/tappay-credit-card.js')
@endsection
