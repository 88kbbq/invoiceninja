@php
    $gateway_instance = $gateway instanceof \App\Models\CompanyGateway ? $gateway : $gateway->company_gateway;
    $token_billing_string = 'true';

    if ($gateway_instance->token_billing == 'off' || $gateway_instance->token_billing == 'optin') {
        $token_billing_string = 'false';
    }

    if (isset($pre_payment) && $pre_payment == '1' && isset($is_recurring) && $is_recurring == '1') {
        $token_billing_string = 'true';
    }
@endphp

@push('head')
    <meta name="app-id" content="{{ $app_id }}">
    <meta name="app-key" content="{{ $app_key }}">
    <meta name="server-type" content="{{ $server_type }}">
    <meta name="value" content="{{ $value }}">
    <meta name="currency" content="{{ $currency }}">
    <meta name="payment-hash" content="{{ $payment_hash }}">

    <script src="https://js.tappaysdk.com/sdk/tpdirect/v5.19.2"></script>

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

        .input-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            color: #374151;
            margin-bottom: 0.25rem;
        }
    </style>
@endpush

<div class="rounded-lg border bg-card text-card-foreground shadow-sm overflow-hidden py-5 bg-white" id="tappay-credit-card-payment" data-sdk-src="https://js.tappaysdk.com/sdk/tpdirect/v5.19.2" data-app-id="{{ $app_id }}" data-app-key="{{ $app_key }}" data-server-type="{{ $server_type }}">
    <form action="{{ route('client.payments.response') }}" method="post" id="server-response">
        @csrf
        <input type="hidden" name="prime" id="prime-input">
        <input type="hidden" name="cardholder_name" id="cardholder-name-input">
        <input type="hidden" name="store_card" value="{{ $token_billing_string }}">
        <input type="hidden" name="payment_hash" value="{{ $payment_hash }}">
        <input type="hidden" name="company_gateway_id" value="{{ $gateway->company_gateway->id }}">
        <input type="hidden" name="payment_method_id" value="{{ $payment_method_id }}">
        <input type="hidden" name="value" value="{{ $value }}">
        <input type="hidden" name="raw_value" value="{{ $raw_value }}">
        <input type="hidden" name="currency" value="{{ $currency }}">
        <input type="hidden" name="token" value="">
    </form>

    <div class="alert alert-failure mb-4" hidden id="errors"></div>

    @component('portal.ninja2020.components.general.card-element', ['title' => ctrans('texts.payment_type')])
        {{ ctrans('texts.credit_card') }}
    @endcomponent

    @include('portal.ninja2020.gateways.includes.payment_details')

    @component('portal.ninja2020.components.general.card-element', ['title' => ctrans('texts.pay_with')])
        <ul class="list-none space-y-2">
            @if(count($tokens) > 0)
                @foreach($tokens as $token)
                    <li class="py-2 hover:bg-gray-100 rounded transition-colors duration-150">
                        <label class="flex items-center cursor-pointer px-2">
                            <input
                                type="radio"
                                data-token="{{ $token->hashed_id }}"
                                name="payment-type"
                                class="form-radio text-indigo-600 rounded-full cursor-pointer toggle-payment-with-token"/>
                            <span class="ml-2 cursor-pointer">**** {{ $token->meta?->last4 }}</span>
                        </label>
                    </li>
                @endforeach
            @endif

            <li class="py-2 hover:bg-gray-100 rounded transition-colors duration-150">
                <label class="flex items-center cursor-pointer px-2">
                    <input
                        type="radio"
                        id="toggle-payment-with-credit-card"
                        class="form-radio text-indigo-600 rounded-full cursor-pointer"
                        name="payment-type"
                        checked/>
                    <span class="ml-2 cursor-pointer">{{ __('texts.new_card') }}</span>
                </label>
            </li>
        </ul>
    @endcomponent

    @include('portal.ninja2020.gateways.tappay.includes.card_widget')
    @include('portal.ninja2020.gateways.includes.pay_now')

    @assets
        @vite('resources/js/clients/payments/tappay-credit-card.js')
    @endassets
</div>
