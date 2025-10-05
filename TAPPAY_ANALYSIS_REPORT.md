# TapPay Payment Gateway Analysis Report
## Invoice Ninja v5 Integration Planning

**Date:** October 5, 2025
**Purpose:** Analyze existing payment drivers to determine best base for TapPay implementation
**Status:** Planning Phase - No Code Changes Yet

---

## TapPay Overview

### Payment Methods Supported
1. **Direct Pay** - Customers enter credit card details directly on merchant's frontend
2. **Electronic Payments** - E-wallet transactions (LINE Pay, 街口支付, etc.)
3. **Token Pay** - Mobile payment services (Apple Pay, Google Pay, Samsung Pay)

### Supported Card Networks
- Visa
- Mastercard
- JCB
- AMEX (American Express)
- UnionPay

### API Characteristics
- **SDK:** No official PHP SDK (REST API implementation required)
- **Documentation:** https://docs.tappaysdk.com
- **Portal:** https://portal.tappaysdk.com (requires login)
- **Support:** support@cherri.tech
- **Authentication:** API keys (Partner Key, Merchant ID)
- **Frontend:** JavaScript SDK for tokenization (GetPrime)
- **Backend:** REST API for payment processing

### Integration Flow
1. **Frontend:** Collect card details → TapPay JS SDK → Generate Prime (token)
2. **Backend:** Receive Prime → Create payment via REST API → Process response
3. **Webhook:** Receive payment status updates (optional)

---

## Invoice Ninja Payment Driver Architecture

### Core Structure

**All payment drivers must extend:** `App\PaymentDrivers\BaseDriver`

**Required Components:**
1. Main Driver Class (`TapPayPaymentDriver.php`)
2. Payment Method Classes (e.g., `TapPay/CreditCard.php`)
3. Optional: Utilities, Webhooks, Jobs

### Required Properties

```php
class TapPayPaymentDriver extends BaseDriver
{
    // Gateway capabilities
    public $refundable = true;           // Supports refunds
    public $token_billing = true;        // Supports saving cards
    public $can_authorise_credit_card = true; // Supports auth-only

    // Gateway client instance
    public $gateway;  // or public $tappay;

    // Payment method instance
    public $payment_method;

    // Supported payment methods map
    public static $methods = [
        GatewayType::CREDIT_CARD => CreditCard::class,
        // Add more as needed
    ];

    // System log type constant
    public const SYSTEM_LOG_TYPE = SystemLog::TYPE_TAPPAY;
}
```

### Required Methods

```php
// Initialize gateway API client
public function init(): self

// Return array of supported gateway types
public function gatewayTypes(): array

// Set the payment method to use
public function setPaymentMethod($payment_method_id)

// Authorization views/responses (for saving payment methods)
public function authorizeView(array $data)
public function authorizeResponse($request)

// Payment processing views/responses
public function processPaymentView(array $data)
public function processPaymentResponse($request)

// Refund a payment
public function refund(Payment $payment, $amount, $return_client_response = false)

// Process payment with saved token
public function tokenBilling(ClientGatewayToken $cgt, PaymentHash $payment_hash)

// Handle webhooks (optional)
public function processWebhookRequest()
```

---

## Comparison of Existing Payment Drivers

### Analysis Matrix

| Driver | Lines | Complexity | Refundable | Token Billing | Auth | Multiple Methods | Best For |
|--------|-------|------------|------------|---------------|------|------------------|----------|
| **Razorpay** | 112 | ⭐ Simple | No | No | No | 1 (Hosted) | **Simplest example** |
| **CheckoutCom** | 584 | ⭐⭐ Moderate | Yes | Yes | Yes | 1 (Credit Card) | **Similar to TapPay** |
| **Braintree** | 554 | ⭐⭐ Moderate | Yes | Yes | Yes | 3 (CC, PayPal, Venmo) | Multiple methods |
| **Stripe** | 1172 | ⭐⭐⭐ Complex | Yes | Yes | Yes | 15+ methods | Full-featured reference |
| **Authorize.Net** | 339 | ⭐⭐ Moderate | Yes | Yes | Yes | 2 (CC, ACH) | Traditional gateway |

### Detailed Analysis

#### 1. Razorpay (SIMPLEST - 112 lines)

**Structure:**
```
RazorpayPaymentDriver.php (112 lines)
└── Razorpay/
    └── Hosted.php (payment method)
```

**Pros:**
- ✅ Minimal code - easy to understand
- ✅ Clear, simple structure
- ✅ Uses composer package (`razorpay/razorpay`)
- ✅ Good starting template

**Cons:**
- ❌ Only supports hosted redirect (not direct payment)
- ❌ No refund support
- ❌ No token billing
- ❌ Too simple for TapPay's features

**Key Characteristics:**
- Hosted payment page (redirects to Razorpay)
- Minimal backend logic
- Good for understanding basic structure

**Verdict:** ⭐⭐ Good for learning structure, but too simple for TapPay

---

#### 2. CheckoutCom (RECOMMENDED - 584 lines)

**Structure:**
```
CheckoutComPaymentDriver.php (584 lines)
└── CheckoutCom/
    ├── CreditCard.php (payment method)
    ├── CheckoutWebhook.php (webhook handler)
    └── Utilities.php (helper methods)
```

**Pros:**
- ✅ **Perfect complexity level** - not too simple, not too complex
- ✅ **Uses official SDK** (`checkout/checkout-sdk-php`)
- ✅ **Direct payment flow** (customer enters card on your site)
- ✅ **Token billing support** (save cards for future use)
- ✅ **3DS support** (3D Secure authentication)
- ✅ **Clean separation** of concerns
- ✅ **Well-documented** with inline comments
- ✅ **Modern API** (similar to TapPay's REST approach)

**Cons:**
- ⚠️ Single payment method (but easy to extend)

**Key Characteristics:**
- **Frontend tokenization** → Backend processing (matches TapPay flow)
- SDK handles API communication
- Proper error handling and logging
- Supports both sandbox and production

**Code Example:**
```php
// Clean initialization
public function init()
{
    $builder = CheckoutSdk::builder()
        ->staticKeys()
        ->environment($this->company_gateway->getConfigField('testMode')
            ? Environment::sandbox()
            : Environment::production())
        ->publicKey($this->company_gateway->getConfigField('publicApiKey'))
        ->secretKey($this->company_gateway->getConfigField('secretApiKey'));

    $this->gateway = $builder->build();
    return $this;
}

// Simple payment method setup
public function setPaymentMethod($payment_method = null): self
{
    $class = self::$methods[GatewayType::CREDIT_CARD];
    $this->payment_method = new $class($this);
    return $this;
}
```

**Verdict:** ⭐⭐⭐⭐⭐ **BEST CHOICE for TapPay implementation**

---

#### 3. Braintree (ALTERNATIVE - 554 lines)

**Structure:**
```
BraintreePaymentDriver.php (554 lines)
└── Braintree/
    ├── CreditCard.php
    ├── PayPal.php
    ├── Venmo.php
    └── utilities/...
```

**Pros:**
- ✅ Multiple payment methods
- ✅ Uses official SDK (`braintree/braintree_php`)
- ✅ Token billing support
- ✅ Good refund implementation

**Cons:**
- ⚠️ More complex than needed
- ⚠️ Braintree-specific patterns

**Verdict:** ⭐⭐⭐ Good alternative, but CheckoutCom is cleaner

---

#### 4. Stripe (REFERENCE - 1172 lines)

**Structure:**
```
StripePaymentDriver.php (1172 lines)
└── Stripe/
    ├── CreditCard.php
    ├── ACH.php
    ├── SEPA.php
    ├── Alipay.php
    ├── ApplePay.php
    ├── ... (15+ payment methods)
    ├── Jobs/ (webhook jobs)
    └── Connect/ (Stripe Connect support)
```

**Pros:**
- ✅ Most comprehensive example
- ✅ Handles every edge case
- ✅ Best practices for production
- ✅ Excellent error handling
- ✅ Webhook processing
- ✅ Uses official SDK

**Cons:**
- ❌ Too complex for initial implementation
- ❌ Stripe-specific features (Connect, etc.)
- ❌ Overwhelming for learning

**Verdict:** ⭐⭐⭐⭐ Excellent reference for specific features, but start with CheckoutCom

---

#### 5. Authorize.Net (TRADITIONAL - 339 lines)

**Structure:**
```
AuthorizePaymentDriver.php (339 lines)
└── Authorize/
    ├── CreditCard.php
    └── ACH.php
```

**Pros:**
- ✅ Traditional payment gateway pattern
- ✅ Clear credit card flow
- ✅ Uses official SDK

**Cons:**
- ⚠️ Older API patterns
- ⚠️ Less modern than CheckoutCom

**Verdict:** ⭐⭐⭐ Solid example, but CheckoutCom is more modern

---

## Recommendation: Use CheckoutCom as Base

### Why CheckoutCom is the Best Match for TapPay

| Feature | TapPay | CheckoutCom | Match? |
|---------|--------|-------------|--------|
| **Frontend Tokenization** | Yes (GetPrime) | Yes (Checkout.js) | ✅ Perfect |
| **Direct Payment** | Yes | Yes | ✅ Perfect |
| **Credit Card Processing** | Yes | Yes | ✅ Perfect |
| **Token Billing** | Yes | Yes | ✅ Perfect |
| **Official SDK** | No (REST API) | Yes | ⚠️ We'll use Guzzle |
| **3DS Support** | Yes | Yes | ✅ Perfect |
| **Refunds** | Yes | Yes | ✅ Perfect |
| **Webhooks** | Yes | Yes | ✅ Perfect |
| **Sandbox/Production** | Yes | Yes | ✅ Perfect |
| **API Key Auth** | Yes | Yes | ✅ Perfect |

### Implementation Approach

**Phase 1: Basic Credit Card Payment** (Based on CheckoutCom structure)
```
app/PaymentDrivers/
├── TapPayPaymentDriver.php          # Main driver (based on CheckoutComPaymentDriver)
└── TapPay/
    ├── CreditCard.php                # Credit card method (based on CheckoutCom/CreditCard)
    ├── TapPayWebhook.php             # Webhook handler (based on CheckoutWebhook)
    └── Utilities.php                 # Helper methods
```

**Phase 2: Additional Payment Methods** (After Phase 1 working)
```
app/PaymentDrivers/TapPay/
├── CreditCard.php       # ✅ Phase 1
├── ApplePay.php         # Phase 2 - Token Pay
├── GooglePay.php        # Phase 2 - Token Pay
└── LinePay.php          # Phase 2 - E-wallet
```

---

## TapPay-Specific Implementation Notes

### API Client (No Official SDK)

Since TapPay doesn't have an official PHP SDK, we'll use **Guzzle HTTP client** (already included in Laravel):

```php
// In TapPayPaymentDriver::init()
public function init(): self
{
    $this->gateway = new \GuzzleHttp\Client([
        'base_uri' => $this->company_gateway->getConfigField('testMode')
            ? 'https://sandbox.tappaysdk.com/'
            : 'https://prod.tappaysdk.com/',
        'headers' => [
            'Content-Type' => 'application/json',
            'x-api-key' => $this->company_gateway->getConfigField('partnerKey'),
        ],
        'timeout' => 30,
    ]);

    return $this;
}
```

### Frontend Integration (GetPrime)

**TapPay's flow:**
1. Customer enters card details
2. TapPay.js generates "Prime" (token)
3. Submit Prime to backend
4. Backend uses Prime to charge

**Implementation:**
```blade
{{-- resources/views/gateways/tappay/credit_card/pay.blade.php --}}
<script src="https://js.tappaysdk.com/tpdirect/v5.14.0"></script>
<script>
    TPDirect.setupSDK(
        '{{ $gateway->company_gateway->getConfigField('appId') }}',
        '{{ $gateway->company_gateway->getConfigField('appKey') }}',
        '{{ $gateway->company_gateway->getConfigField('testMode') ? 'sandbox' : 'production' }}'
    );

    // Setup card fields
    TPDirect.card.setup({
        fields: {
            number: { element: '#card-number' },
            expirationDate: { element: '#card-expiry' },
            ccv: { element: '#card-cvc' }
        }
    });

    // Get Prime when form submitted
    TPDirect.card.getPrime(function(result) {
        if (result.status !== 0) {
            alert('Card validation failed');
            return;
        }

        // Submit Prime to backend
        document.getElementById('prime').value = result.card.prime;
        document.getElementById('payment-form').submit();
    });
</script>
```

### Backend Payment Processing

```php
// In TapPay/CreditCard.php::paymentResponse()
public function paymentResponse($request)
{
    $prime = $request->input('prime');

    // Create payment via TapPay API
    $response = $this->tappay->gateway->post('tpc/payment/pay-by-prime', [
        'json' => [
            'prime' => $prime,
            'partner_key' => $this->tappay->company_gateway->getConfigField('partnerKey'),
            'merchant_id' => $this->tappay->company_gateway->getConfigField('merchantId'),
            'amount' => $this->tappay->payment_hash->fee_total,
            'currency' => $this->tappay->client->currency()->code,
            'details' => 'Invoice Payment',
            'cardholder' => [
                'phone_number' => $this->tappay->client->phone,
                'name' => $this->tappay->client->name,
                'email' => $this->tappay->client->present()->email(),
            ],
            'remember' => true, // For token billing
        ]
    ]);

    $data = json_decode($response->getBody()->getContents());

    if ($data->status === 0) {
        // Success
        return $this->processSuccessfulPayment($data);
    } else {
        // Failure
        throw new PaymentFailed($data->msg, $data->status);
    }
}
```

### Required Configuration Fields

```php
// In TapPayPaymentDriver
public function getRequiredFields(): array
{
    return [
        'partnerKey' => [
            'label' => 'Partner Key',
            'type' => 'text',
            'required' => true,
        ],
        'merchantId' => [
            'label' => 'Merchant ID',
            'type' => 'text',
            'required' => true,
        ],
        'appId' => [
            'label' => 'App ID',
            'type' => 'text',
            'required' => true,
        ],
        'appKey' => [
            'label' => 'App Key (Frontend)',
            'type' => 'text',
            'required' => true,
        ],
        'testMode' => [
            'label' => 'Test Mode',
            'type' => 'checkbox',
            'required' => false,
        ],
    ];
}
```

---

## Database Migration Required

Create migration to register TapPay gateway:

```php
// database/migrations/YYYY_MM_DD_create_tappay_gateway.php
Schema::table('gateways', function (Blueprint $table) {
    // TapPay gateway record will be inserted via migration
});

// In migration up()
DB::table('gateways')->insert([
    'name' => 'TapPay',
    'key' => 'tappay',
    'provider' => 'TapPay',
    'is_offsite' => false,  // Direct payment on merchant site
    'is_secure' => true,
    'fields' => json_encode([
        'partnerKey' => '',
        'merchantId' => '',
        'appId' => '',
        'appKey' => '',
        'testMode' => false,
    ]),
    'visible' => true,
    'site_url' => 'https://www.tappaysdk.com',
    'default_gateway_type_id' => GatewayType::CREDIT_CARD,
]);
```

---

## SystemLog Type

Add to `app/Models/SystemLog.php`:

```php
public const TYPE_TAPPAY = 340; // Choose next available number
```

---

## File Structure Comparison

### CheckoutCom Structure (Our Base)
```
app/PaymentDrivers/
├── CheckoutComPaymentDriver.php (584 lines)
└── CheckoutCom/
    ├── CreditCard.php (400 lines)
    ├── CheckoutWebhook.php (150 lines)
    └── Utilities.php (100 lines)

resources/views/gateways/checkout/
└── credit_card/
    ├── authorize.blade.php
    └── pay.blade.php
```

### Proposed TapPay Structure (Mirroring CheckoutCom)
```
app/PaymentDrivers/
├── TapPayPaymentDriver.php (~600 lines estimated)
└── TapPay/
    ├── CreditCard.php (~400 lines estimated)
    ├── TapPayWebhook.php (~150 lines estimated)
    └── Utilities.php (~100 lines estimated)

resources/views/gateways/tappay/
└── credit_card/
    ├── authorize.blade.php (for saving cards)
    └── pay.blade.php (for payment)
```

---

## Testing Strategy

### Phase 1: Local Development
1. Set up TapPay sandbox account
2. Get sandbox credentials
3. Implement basic credit card payment
4. Test with TapPay test cards

### Phase 2: Staging
1. Test with Invoice Ninja staging environment
2. Verify all flows work
3. Test refunds
4. Test token billing

### Phase 3: Production
1. Get production credentials
2. Deploy to production
3. Process test transaction
4. Monitor for issues

### TapPay Test Cards (Sandbox)
```
Card Number: 4242 4242 4242 4242
Expiry: Any future date
CVC: Any 3 digits
```
(Confirm with TapPay documentation for official test cards)

---

## Next Steps

### Immediate Actions
1. ✅ **Analysis Complete** (this document)
2. ⏳ **Update CLAUDE.md** with TapPay documentation links
3. ⏳ **Get TapPay sandbox credentials** (register at portal.tappaysdk.com)
4. ⏳ **Review CheckoutCom implementation in detail**
5. ⏳ **Create implementation plan** with timeline

### Implementation Phases

**Phase 1: Basic Implementation** (~8-12 hours)
- [ ] Create TapPayPaymentDriver.php
- [ ] Create TapPay/CreditCard.php
- [ ] Create views for payment form
- [ ] Integrate TapPay.js SDK
- [ ] Test basic payment flow

**Phase 2: Advanced Features** (~4-6 hours)
- [ ] Implement refunds
- [ ] Implement token billing (save cards)
- [ ] Add 3D Secure support
- [ ] Create webhook handler

**Phase 3: Testing & Polish** (~4-6 hours)
- [ ] Write unit tests
- [ ] Write feature tests
- [ ] Test all edge cases
- [ ] Add error handling
- [ ] Add logging

**Phase 4: Additional Payment Methods** (~6-8 hours per method)
- [ ] Apple Pay support
- [ ] Google Pay support
- [ ] LINE Pay support (if needed)

---

## Risk Assessment

### Low Risk
- ✅ Using proven CheckoutCom structure
- ✅ Similar API patterns (tokenization → charge)
- ✅ Good documentation from TapPay
- ✅ Guzzle HTTP client (well-tested)

### Medium Risk
- ⚠️ No official PHP SDK (need to implement REST API manually)
- ⚠️ TapPay documentation might be incomplete
- ⚠️ 3D Secure flow might be complex
- ⚠️ Webhook signature verification needs research

### Mitigation Strategies
1. **Start small** - Basic credit card only
2. **Test thoroughly** - Use sandbox extensively
3. **Reference CheckoutCom** - Follow proven patterns
4. **Contact TapPay support** - Ask questions early
5. **Iterate** - Build features incrementally

---

## Questions for TapPay Support

Before implementation, clarify with TapPay:

1. **API Endpoints**
   - What's the exact base URL for sandbox/production?
   - What's the payment endpoint structure?
   - What's the refund endpoint?

2. **Authentication**
   - How are API calls authenticated?
   - Is there request signing?
   - What headers are required?

3. **3D Secure**
   - How is 3DS handled?
   - Do we need to implement redirect flow?
   - Is 3DS automatic or manual?

4. **Webhooks**
   - What events trigger webhooks?
   - How are webhook signatures verified?
   - What's the retry policy?

5. **Token Billing**
   - How are cards tokenized?
   - What's the token format?
   - How long are tokens valid?

6. **Testing**
   - What test cards are available?
   - How to test failures/declines?
   - Sandbox limitations?

---

## Resources

### TapPay Documentation
- **Main Docs:** https://docs.tappaysdk.com
- **Portal:** https://portal.tappaysdk.com
- **Support:** support@cherri.tech

### Invoice Ninja Documentation
- **Payment Gateways Guide:** https://invoiceninja.github.io/en/payment-gateways/
- **Developer Guide:** https://invoiceninja.github.io/en/developer-guide/
- **API Docs:** https://api-docs.invoicing.co/
- **Forum:** https://forum.invoiceninja.com
- **Slack:** http://slack.invoiceninja.com

### Reference Code
- **CheckoutCom Driver:** `app/PaymentDrivers/CheckoutComPaymentDriver.php`
- **CheckoutCom Credit Card:** `app/PaymentDrivers/CheckoutCom/CreditCard.php`
- **BaseDriver:** `app/PaymentDrivers/BaseDriver.php`
- **Stripe (Reference):** `app/PaymentDrivers/StripePaymentDriver.php`

---

## Conclusion

**Recommendation: Use CheckoutCom as the base template for TapPay implementation**

**Reasoning:**
1. ✅ **Perfect complexity match** - Not too simple, not too complex
2. ✅ **Similar payment flow** - Frontend tokenization, backend processing
3. ✅ **Similar features** - Refunds, token billing, 3DS
4. ✅ **Clean architecture** - Easy to understand and modify
5. ✅ **Well-tested pattern** - Proven in production

**Estimated Total Time:** 20-30 hours for full implementation with all features

**Confidence Level:** High - CheckoutCom provides an excellent template that closely matches TapPay's architecture and features.

---

**Report Prepared By:** Claude Code (Anthropic)
**Date:** October 5, 2025
**Status:** Ready for Implementation Planning
