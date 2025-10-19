<div id="tappay--payment-container">
    @unless(isset($show_name) && $show_name == false)
        @component('portal.ninja2020.components.general.card-element', ['title' => ctrans('texts.cardholder_name')])
            <input
                class="input w-full"
                id="cardholder-name"
                type="text"
                placeholder="{{ ctrans('texts.cardholder_name') }}"
                autocomplete="cc-name"
                required>
        @endcomponent
    @endunless

    @unless(isset($show_card_element) && $show_card_element == false)
        {{-- Card Number --}}
        @component('portal.ninja2020.components.general.card-element', ['title' => ctrans('texts.card_number')])
            <div class="tpfield tpfield--number" id="tappay-card-number"></div>
            <div id="card-number-error" class="field-error hidden"></div>
        @endcomponent

        {{-- Expiration & CVV on same row --}}
        <div class="px-4 py-2 sm:px-6 lg:grid lg:grid-cols-3 lg:gap-4 lg:flex lg:items-center">
            <dt class="text-sm leading-5 font-medium text-gray-500 mr-4">
                {{ ctrans('texts.expiration_date') }}
            </dt>
            <dd class="mt-1 text-sm leading-5 text-gray-900 sm:mt-0 sm:col-span-2 flex gap-4">
                <div class="flex-1">
                    <div class="tpfield tpfield--expiry" id="tappay-card-expiry"></div>
                    <div id="card-expiry-error" class="field-error hidden"></div>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-sm text-gray-600 whitespace-nowrap">{{ ctrans('texts.cvv') }}</span>
                    <div>
                        <div class="tpfield tpfield--cvc" id="tappay-card-cvc"></div>
                        <div id="card-cvc-error" class="field-error hidden"></div>
                    </div>
                </div>
            </dd>
        </div>
    @endunless
</div>

@unless(isset($show_save_method) && $show_save_method == false)
    @include('portal.ninja2020.gateways.includes.save_card')
@endunless
