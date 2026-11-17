<?php

namespace App\Services\TaiwanEInvoice;

use App\Models\Payment;
use App\Models\Invoice;
use Illuminate\Support\Facades\Http;
use Exception;

/**
 * Taiwan E-Invoice Service - Amego API Integration
 *
 * API Documentation: /private/tmp/amego-api.txt
 * API URL: https://invoice-api.amego.tw
 *
 * Supports multiple issuing companies:
 * - benfire: 犇火燻寶有限公司 (GUI: 53523822) - Default, used for TapPay payments
 * - bameixin: 霸美燻王有限公司 (GUI: 83464574) - Available for manual payments
 */
class TaiwanEInvoiceService
{
    protected string $apiUrl;
    protected string $appKey;
    protected string $companyGui;
    protected string $companyCode;
    protected bool $testMode;

    /** Amego: OrderId already used (also after the e-invoice is voided). */
    protected const ERROR_DUPLICATE_ORDER_ID = 3040171;

    /**
     * Company configurations
     * Each company has its own GUI and App Key for Amego API
     */
    protected const COMPANIES = [
        'benfire' => [
            'name' => '犇火燻寶有限公司',
            'gui_env' => 'TAIWAN_EINVOICE_BENFIRE_GUI',
            'key_env' => 'TAIWAN_EINVOICE_BENFIRE_APP_KEY',
            'gui_default' => '53523822',
        ],
        'bameixin' => [
            'name' => '霸美燻王有限公司',
            'gui_env' => 'TAIWAN_EINVOICE_BAMEIXIN_GUI',
            'key_env' => 'TAIWAN_EINVOICE_BAMEIXIN_APP_KEY',
            'gui_default' => '83464574',
        ],
    ];

    /**
     * @param string $companyCode Company identifier: 'benfire' or 'bameixin'
     */
    public function __construct(string $companyCode = 'benfire')
    {
        $this->apiUrl = 'https://invoice-api.amego.tw';
        // Default to production mode in production environment
        // Only enable test mode if explicitly set or not in production
        $this->testMode = config('app.env') !== 'production' || env('TAIWAN_EINVOICE_TEST_MODE', false);
        $this->companyCode = $companyCode;

        if ($this->testMode) {
            // Test credentials from Amego documentation
            $this->companyGui = '12345678';
            $this->appKey = 'sHeq7t8G1wiQvhAuIM27';
        } else {
            // Production credentials from environment based on company
            $this->setCompanyCredentials($companyCode);
        }
    }

    /**
     * Set credentials based on company code
     */
    protected function setCompanyCredentials(string $companyCode): void
    {
        if (!isset(self::COMPANIES[$companyCode])) {
            throw new Exception("Invalid company code: {$companyCode}. Must be 'benfire' or 'bameixin'.");
        }

        $company = self::COMPANIES[$companyCode];
        $this->companyGui = env($company['gui_env'], $company['gui_default']);
        $this->appKey = env($company['key_env'], '');

        if (empty($this->appKey)) {
            throw new Exception("Missing API key for company: {$company['name']}. Set {$company['key_env']} in .env");
        }
    }

    /**
     * Get company code
     */
    public function getCompanyCode(): string
    {
        return $this->companyCode;
    }

    /**
     * Get company name
     */
    public function getCompanyName(): string
    {
        return self::COMPANIES[$this->companyCode]['name'] ?? 'Unknown';
    }

    /**
     * Get company code from company name
     * Used for reverse lookup when voiding (custom_value4 stores the name)
     */
    public static function getCompanyCodeFromName(string $companyName): ?string
    {
        foreach (self::COMPANIES as $code => $company) {
            if ($company['name'] === $companyName) {
                return $code;
            }
        }
        return null;
    }

    /**
     * Check if a value is a valid company code
     */
    public static function isValidCompanyCode(string $value): bool
    {
        return isset(self::COMPANIES[$value]);
    }

    /**
     * Check if a value is a valid company name
     */
    public static function isValidCompanyName(string $value): bool
    {
        return self::getCompanyCodeFromName($value) !== null;
    }

    /**
     * Generate API signature: md5(data JSON + timestamp + APP Key)
     */
    protected function generateSignature(array $data, int $timestamp): string
    {
        $dataJson = json_encode($data, JSON_UNESCAPED_UNICODE);
        return md5($dataJson . $timestamp . $this->appKey);
    }

    /**
     * Make request to Amego API
     *
     * @throws Exception if API returns error
     */
    protected function makeRequest(string $endpoint, array $data): array
    {
        $timestamp = time();
        $dataJson = json_encode($data, JSON_UNESCAPED_UNICODE);
        $signature = $this->generateSignature($data, $timestamp);

        // Log exact JSON being sent to API
        \Log::info('Taiwan E-Invoice JSON payload', [
            'endpoint' => $endpoint,
            'json' => $dataJson,
            'json_length' => strlen($dataJson),
        ]);

        // Prepare POST data per API documentation
        // Content-Type: application/x-www-form-urlencoded
        // Note: Http::asForm() automatically URL-encodes parameters (like http_build_query)
        $postData = [
            'invoice' => $this->companyGui,
            'data' => $dataJson, // Do NOT manually urlencode - asForm() handles it
            'time' => $timestamp,
            'sign' => $signature,
        ];

        $response = Http::asForm()
            ->timeout(30)
            ->post($this->apiUrl . $endpoint, $postData);

        if (!$response->successful()) {
            throw new Exception('API request failed: ' . $response->body());
        }

        $responseData = $response->json();

        // API returns code: 0 for success
        if (($responseData['code'] ?? -1) !== 0) {
            // Log error response for debugging
            \Log::error('Taiwan E-Invoice API Error', [
                'endpoint' => $endpoint,
                'response_code' => $responseData['code'] ?? null,
                'response_msg' => $responseData['msg'] ?? null,
                'full_response' => $responseData,
            ]);
            throw new Exception($responseData['msg'] ?? 'Unknown API error', (int) ($responseData['code'] ?? 0));
        }

        return $responseData;
    }

    /**
     * Issue Taiwan E-Invoice
     *
     * @param Payment $payment
     * @param string|null $einvoiceEmail Additional email from form
     * @param string|null $buyerGui VAT/GUI number from form (optional override)
     * @return array ['success' => bool, 'receipt_number' => string, 'message' => string]
     */
    public function issueReceipt(Payment $payment, ?string $einvoiceEmail = null, ?string $buyerGui = null): array
    {
        try {
            // Get the first linked invoice
            $invoice = $payment->invoices()->first();
            if (!$invoice) {
                throw new Exception('No invoice linked to this payment');
            }

            $client = $payment->client;

            // Collect all contact emails
            $contactEmails = $client->contacts()
                ->pluck('email')
                ->filter()
                ->unique()
                ->toArray();

            // Add additional email from form if provided
            if (!empty($einvoiceEmail)) {
                $contactEmails[] = $einvoiceEmail;
            }

            // Join emails with comma, or empty string if none
            $buyerEmailAddress = !empty($contactEmails)
                ? implode(',', array_unique($contactEmails))
                : '';

            // Determine buyer GUI number (統一編號) with priority:
            // 1. User input from form (allows override)
            // 2. Client VAT number field
            // 3. Invoice custom_value2 (previously saved)
            $buyerGui = $buyerGui // From request parameter
                ?? $client->vat_number
                ?? $invoice->custom_value2
                ?? '';

            // Clean and validate GUI (remove spaces, ensure 8 digits)
            $buyerGui = preg_replace('/\s+/', '', $buyerGui);
            $isB2B = !empty($buyerGui) && strlen($buyerGui) === 8;

            // E-invoice amounts come from the invoice TOTAL — the figure the
            // customer sees and TapPay charges. Line items are reconciled to it
            // (invoice discount → 折扣 line, rounding → absorbed or 尾差調整).
            // See EInvoiceAmountCalculator for the Amego rules this satisfies.
            $invoiceTaxMultiplier = 1.0;
            if (!$invoice->uses_inclusive_taxes) {
                $invoiceTaxMultiplier += ((float) $invoice->tax_rate1 + (float) $invoice->tax_rate2 + (float) $invoice->tax_rate3) / 100;
            }

            $lines = [];
            foreach ($invoice->line_items as $item) {
                $lines[] = [
                    'description' => $item->product_key ?: $item->notes ?: '服務費',
                    'quantity' => (float) $item->quantity,
                    'gross' => (float) ($item->gross_line_total ?? $item->line_total ?? 0),
                ];
            }

            if (abs((float) $invoice->amount - round((float) $invoice->amount)) > 0.0001) {
                \Log::warning('Taiwan E-Invoice: invoice total has fractional NT$; issuing rounded whole-dollar total (same rounding TapPay charges)', [
                    'invoice_number' => $invoice->number,
                    'invoice_amount' => $invoice->amount,
                ]);
            }

            $calc = EInvoiceAmountCalculator::calculate(
                $lines,
                (float) $invoice->amount,
                $invoiceTaxMultiplier,
                (float) $invoice->discount > 0,
                $isB2B
            );

            $productItems = array_map(fn (array $i) => [
                'Description' => $i['description'],
                'Quantity' => $i['quantity'],
                'Unit' => '式',
                'UnitPrice' => $i['unitPrice'],
                'Amount' => (string) $i['amount'],
                'Remark' => '',
                'TaxType' => '1',  // Always 1 (taxable, 5%)
            ], $calc['items']);

            $salesAmount = $calc['salesAmount'];
            $taxAmount = $calc['taxAmount'];
            $totalAmount = $calc['totalAmount'];

            // Build API request data (OrderId set per attempt below)
            $requestData = [
                'OrderId' => '',
                'BuyerIdentifier' => $isB2B ? $buyerGui : '0000000000',
                'BuyerName' => $isB2B ? $buyerGui : '客人',
                'BuyerAddress' => $client->address1 ?? '',
                'BuyerTelephoneNumber' => '', // Do not pass phone (SMS fees)
                'BuyerEmailAddress' => $buyerEmailAddress,
                'MainRemark' => $invoice->public_notes ?? '',
                'CarrierType' => '', // No carrier
                'CarrierId1' => '',
                'CarrierId2' => '',
                'NPOBAN' => '', // No donation
                'ProductItem' => $productItems,
                'SalesAmount' => (string) $salesAmount,
                'FreeTaxSalesAmount' => '0',
                'ZeroTaxSalesAmount' => '0',
                'TaxType' => '1',
                'TaxRate' => '0.05',  // Taiwan 營業稅; Amego TaxType 1 is always 5%
                'TaxAmount' => (string) $taxAmount,
                'TotalAmount' => (string) $totalAmount,
            ];

            \Log::info('Taiwan E-Invoice API Request', [
                'invoice_number' => $invoice->number,
                'invoice_amount' => $invoice->amount,
                'tax_rates' => [$invoice->tax_rate1, $invoice->tax_rate2, $invoice->tax_rate3],
                'uses_inclusive_taxes' => (bool) $invoice->uses_inclusive_taxes,
                'invoice_discount' => $invoice->discount,
                'salesAmount' => $salesAmount,
                'taxAmount' => $taxAmount,
                'totalAmount' => $totalAmount,
                'isB2B' => $isB2B,
            ]);

            // Amego never reuses an OrderId, even after the e-invoice is voided
            // (error 3040171 "OrderId 重複"). Only when THIS payment's previous
            // e-invoice was voided do we retry with a suffix (INV-xxx-2, -3…).
            // A duplicate without a recorded void may mean an e-invoice already
            // exists for this order, so it is surfaced, never worked around.
            $baseOrderId = 'INV-' . $invoice->number;
            $wasVoided = $payment->custom_value3 === 'voided';
            $maxAttempts = $wasVoided ? 10 : 1;
            for ($attempt = 1; ; $attempt++) {
                $requestData['OrderId'] = $attempt === 1 ? $baseOrderId : $baseOrderId . '-' . $attempt;
                try {
                    $response = $this->makeRequest('/json/f0401', $requestData);
                    break;
                } catch (Exception $e) {
                    if ($e->getCode() !== self::ERROR_DUPLICATE_ORDER_ID) {
                        throw $e;
                    }
                    if ($attempt >= $maxAttempts) {
                        throw new Exception($wasVoided
                            ? "OrderId {$baseOrderId} already used {$attempt} times at Amego"
                            : "An e-invoice already exists at Amego for {$baseOrderId} (OrderId 重複). Check Amego before re-issuing.", $e->getCode(), $e);
                    }
                }
            }

            // Extract receipt number from response
            $receiptNumber = $response['invoice_number'] ?? null;
            if (!$receiptNumber) {
                throw new Exception('No invoice number returned from API');
            }

            // Store receipt data in payment custom fields
            $payment->custom_value1 = $receiptNumber;
            $payment->custom_value2 = now()->format('Y-m-d H:i:s');
            $payment->custom_value3 = 'issued';
            $payment->custom_value4 = $this->getCompanyName(); // Store issuing company name for display
            $payment->save();

            \Log::info('Taiwan E-Invoice issued successfully', [
                'receipt_number' => $receiptNumber,
                'company_code' => $this->companyCode,
                'company_name' => $this->getCompanyName(),
            ]);

            // Save GUI number to invoice.custom_value2 for future reference
            // (only if B2B invoice with valid 8-digit GUI)
            if ($isB2B && !empty($buyerGui)) {
                $invoice->custom_value2 = $buyerGui;
                $invoice->save();
            }

            return [
                'success' => true,
                'receipt_number' => $receiptNumber,
                'message' => 'E-Invoice issued successfully',
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Void Taiwan E-Invoice
     *
     * @param Payment $payment
     * @param string $reason
     * @return array ['success' => bool, 'message' => string]
     */
    public function voidReceipt(Payment $payment, string $reason): array
    {
        try {
            if (empty($payment->custom_value1)) {
                throw new Exception('No receipt number found for this payment');
            }

            $receiptNumber = $payment->custom_value1;

            // Build void request - API expects array of objects directly
            // Per API docs: data field should be array like [{"CancelInvoiceNumber": "AB00001111"}]
            $requestData = [
                [
                    'CancelInvoiceNumber' => $receiptNumber,
                ]
            ];

            // Call Amego API to void invoice
            $this->makeRequest('/json/f0501', $requestData);

            \Log::info('Taiwan E-Invoice voided successfully', [
                'receipt_number' => $receiptNumber,
                'company_code' => $this->companyCode,
                'company_name' => $this->getCompanyName(),
            ]);

            // Clear payment receipt data (but keep custom_value4 for audit trail)
            $payment->custom_value1 = '';
            $payment->custom_value2 = '';
            $payment->custom_value3 = 'voided';
            // custom_value4 keeps the company code for audit purposes
            $payment->save();

            return [
                'success' => true,
                'message' => 'E-Invoice voided successfully',
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
