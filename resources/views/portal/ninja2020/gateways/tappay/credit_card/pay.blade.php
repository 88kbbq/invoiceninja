@extends('portal.ninja2020.layout.payments', ['gateway_title' => 'Credit card', 'card_title' => 'Credit card'])

@php
    $gateway_instance = $gateway instanceof \App\Models\CompanyGateway ? $gateway : $gateway->company_gateway;
    $token_billing_string = 'true';

    if($gateway_instance->token_billing == 'off' || $gateway_instance->token_billing == 'optin'){
        $token_billing_string = 'false';
    }

    if (isset($pre_payment) && $pre_payment == '1' && isset($is_recurring) && $is_recurring == '1') {
        $token_billing_string = 'true';
    }
@endphp

@section('gateway_head')
    <meta name="app-id" content="{{ $app_id }}">
    <meta name="app-key" content="{{ $app_key }}">
    <meta name="server-type" content="{{ $server_type }}">
    <meta name="value" content="{{ $value }}">
    <meta name="currency" content="{{ $currency }}">
    <meta name="payment-hash" content="{{ $payment_hash }}">

    <script src="https://js.tappaysdk.com/sdk/tpdirect/v5.24.0"></script>

@endsection

@section('gateway_content')
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
        <ul class="list-none">
            @if(count($tokens) > 0)
                @foreach($tokens as $token)
                <li class="py-2 cursor-pointer">
                    <label class="mr-4">
                        <input
                            type="radio"
                            data-token="{{ $token->hashed_id }}"
                            name="payment-type"
                            class="form-check-input text-indigo-600 rounded-full cursor-pointer toggle-payment-with-token"/>
                        <span class="ml-1 cursor-pointer">**** {{ $token->meta?->last4 }}</span>
                    </label>
                </li>
                @endforeach
            @endif

            <li class="py-2 cursor-pointer">
                <label>
                    <input
                        type="radio"
                        id="toggle-payment-with-credit-card"
                        class="form-check-input text-indigo-600 rounded-full cursor-pointer"
                        name="payment-type"
                        checked/>
                    <span class="ml-1 cursor-pointer">{{ __('texts.new_card') }}</span>
                </label>
            </li>
        </ul>
    @endcomponent

    @include('portal.ninja2020.gateways.tappay.includes.card_widget')
    @include('portal.ninja2020.gateways.includes.pay_now')

@endsection

@section('gateway_footer')
    @vite('resources/js/clients/payments/tappay-credit-card.js')
@endsection
