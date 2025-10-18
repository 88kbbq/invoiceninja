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
        @component('portal.ninja2020.components.general.card-element-single')
            <div class="space-y-4">
                <div>
                    <div class="tpfield-row">
                        <label class="tpfield-label">
                            {{ ctrans('texts.card_number') }}
                        </label>
                        <div class="tpfield tpfield--number" id="tappay-card-number"></div>
                    </div>
                    <div id="card-number-error" class="field-error hidden"></div>
                </div>

                <div>
                    <div class="tpfield-row">
                        <label class="tpfield-label">
                            {{ ctrans('texts.expiration_date') }}
                        </label>
                        <div class="tpfield tpfield--expiry" id="tappay-card-expiry"></div>
                    </div>
                    <div id="card-expiry-error" class="field-error hidden"></div>
                </div>

                <div>
                    <div class="tpfield-row">
                        <label class="tpfield-label">
                            {{ ctrans('texts.cvv') }}
                        </label>
                        <div class="tpfield tpfield--cvc" id="tappay-card-cvc"></div>
                    </div>
                    <div id="card-cvc-error" class="field-error hidden"></div>
                </div>
            </div>
        @endcomponent
    @endunless
</div>

@unless(isset($show_save_method) && $show_save_method == false)
    @include('portal.ninja2020.gateways.includes.save_card')
@endunless
