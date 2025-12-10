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
     * Calculate exact UnitPrice and Amount that satisfy: Quantity × UnitPrice = Amount
     *
     * The Amego API requires this equation to be EXACT (no rounding errors).
     * For decimal quantities (e.g., 1.5), we need to find integer values that work.
     *
     * @param float $quantity The item quantity (can be decimal)
     * @param float $rawAmount The desired amount before adjustment
     * @return array [int $unitPrice, int $amount]
     */
    protected function calculateExactAmounts(float $quantity, float $rawAmount): array
    {
        // For integer quantities, simple rounding works
        if (floor($quantity) == $quantity) {
            $amount = (int) round($rawAmount);
            $unitPrice = (int) round($amount / $quantity);
            return [$unitPrice, $amount];
        }

        // For decimal quantities, we need to find Amount where Amount/Quantity is an integer
        // Strategy: try rounding down, then up, and pick the one that gives integer UnitPrice
        $amountFloor = (int) floor($rawAmount);
        $amountCeil = (int) ceil($rawAmount);

        // Check if floor gives an integer UnitPrice
        $unitPriceFloor = $amountFloor / $quantity;
        if (abs($unitPriceFloor - round($unitPriceFloor)) < 0.0001) {
            return [(int) round($unitPriceFloor), $amountFloor];
        }

        // Check if ceil gives an integer UnitPrice
        $unitPriceCeil = $amountCeil / $quantity;
        if (abs($unitPriceCeil - round($unitPriceCeil)) < 0.0001) {
            return [(int) round($unitPriceCeil), $amountCeil];
        }

        // Neither works directly - search nearby values
        // For quantity like 1.5, Amount must be divisible by 1.5 (i.e., Amount × 2 / 3 is integer)
        // Search within ±10 of the raw amount for a valid combination
        for ($offset = 1; $offset <= 10; $offset++) {
            // Try floor - offset
            $tryAmount = $amountFloor - $offset;
            $tryUnitPrice = $tryAmount / $quantity;
            if ($tryAmount > 0 && abs($tryUnitPrice - round($tryUnitPrice)) < 0.0001) {
                return [(int) round($tryUnitPrice), $tryAmount];
            }

            // Try ceil + offset
            $tryAmount = $amountCeil + $offset;
            $tryUnitPrice = $tryAmount / $quantity;
            if (abs($tryUnitPrice - round($tryUnitPrice)) < 0.0001) {
                return [(int) round($tryUnitPrice), $tryAmount];
            }
        }

        // Fallback: use rounded values (may cause API error, but logged for debugging)
        $amount = (int) round($rawAmount);
        $unitPrice = (int) round($amount / $quantity);
        \Log::warning('Could not find exact Amount/UnitPrice for decimal quantity', [
            'quantity' => $quantity,
            'rawAmount' => $rawAmount,
            'fallback_unitPrice' => $unitPrice,
            'fallback_amount' => $amount,
        ]);

        return [$unitPrice, $amount];
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
            throw new Exception($responseData['msg'] ?? 'Unknown API error');
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

            // Determine tax handling from Invoice Ninja settings
            // tax_rate1 > 0 (e.g., "5.000000") → Tax EXCLUSIVE (tax added on top)
            // tax_rate1 = 0 (e.g., "0.000000") → Tax INCLUSIVE (tax already in prices)
            $taxRate = (float) $invoice->tax_rate1;
            $taxIsExclusive = $taxRate > 0;
            $taxMultiplier = $taxIsExclusive ? (1 + ($taxRate / 100)) : 1.05;  // Use 1.05 for inclusive

            // Prepare line items
            $productItems = [];
            $itemsTotal = 0;
            $itemsTotalTaxInclusive = 0;

            foreach ($invoice->line_items as $item) {
                $quantity = (float) $item->quantity;
                $unitPriceBase = (int) round((float) $item->cost);

                // Calculate amounts based on B2B/B2C and tax exclusive/inclusive
                if ($isB2B) {
                    if ($taxIsExclusive) {
                        // Tax EXCLUSIVE for B2B: UnitPrice must be tax-inclusive for API
                        // API validates: Quantity × UnitPrice = Amount (EXACT), then Sum(Amount) ÷ 1.05 = SalesAmount
                        $rawUnitPrice = $unitPriceBase * $taxMultiplier;
                        $rawAmount = $quantity * $rawUnitPrice;

                        // Ensure Quantity × UnitPrice = Amount exactly (API requirement)
                        // For decimal quantities, we need to find integer UnitPrice and Amount that satisfy this
                        list($unitPrice, $amount) = $this->calculateExactAmounts($quantity, $rawAmount);

                        $itemsTotal += (int) round($quantity * $unitPriceBase);  // Track tax-exclusive
                        $itemsTotalTaxInclusive += $amount;
                    } else {
                        // Tax INCLUSIVE for B2B: Prices already include tax
                        // API still validates: Sum(Amount) ÷ 1.05 = SalesAmount
                        $rawAmount = $quantity * $unitPriceBase;

                        // Ensure Quantity × UnitPrice = Amount exactly
                        list($unitPrice, $amount) = $this->calculateExactAmounts($quantity, $rawAmount);

                        $itemsTotal += (int) round($amount / $taxMultiplier);  // Calculate tax-exclusive
                        $itemsTotalTaxInclusive += $amount;
                    }
                } else {
                    // B2C: No tax multiplication needed
                    $rawAmount = $quantity * $unitPriceBase;

                    // Ensure Quantity × UnitPrice = Amount exactly
                    list($unitPrice, $amount) = $this->calculateExactAmounts($quantity, $rawAmount);

                    $itemsTotal += $amount;
                    $itemsTotalTaxInclusive += $amount;
                }

                // Format quantity without trailing zeros (6 not 6.0, but keep 2.5)
                $quantityStr = rtrim(rtrim(number_format($quantity, 7, '.', ''), '0'), '.');

                $productItems[] = [
                    'Description' => $item->product_key ?: $item->notes ?: '服務費',
                    'Quantity' => $quantityStr,
                    'Unit' => '式',
                    'UnitPrice' => (string) $unitPrice,
                    'Amount' => (string) $amount,
                    'Remark' => '',
                    'TaxType' => '1',  // Always 1 (taxable) for now
                ];
            }

            // Calculate totals based on B2B vs B2C
            if ($isB2B) {
                // B2B (with GUI): API validates Sum(ProductItem.Amount) ÷ 1.05 = SalesAmount
                // ProductItem amounts are tax-inclusive (sum stored in itemsTotalTaxInclusive)
                // SalesAmount MUST be derived from TotalAmount to pass API validation
                // (cannot use independent itemsTotal because Amount adjustments affect the total)
                $totalAmount = $itemsTotalTaxInclusive;
                $salesAmount = (int) round($totalAmount / $taxMultiplier);  // Derive from actual total
                $taxAmount = $totalAmount - $salesAmount;
            } else {
                // B2C (no GUI): No tax separation
                // ProductItem amounts are tax-inclusive already
                $salesAmount = $itemsTotalTaxInclusive;
                $taxAmount = 0;
                $totalAmount = $itemsTotalTaxInclusive;
            }

            // Build API request data
            $requestData = [
                'OrderId' => 'INV-' . $invoice->number,
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
                'SalesAmount' => (string) $salesAmount,           // String per API example
                'FreeTaxSalesAmount' => '0',                      // String per API example
                'ZeroTaxSalesAmount' => '0',                      // String per API example
                'TaxType' => '1',                                 // String - always 1 (taxable) for now
                'TaxRate' => $taxIsExclusive ? (string)($taxRate / 100) : '0.05',  // Use invoice tax rate
                'TaxAmount' => (string) $taxAmount,               // String per API example
                'TotalAmount' => (string) $totalAmount,           // String per API example
            ];

            // Log request data for debugging
            \Log::info('Taiwan E-Invoice API Request', [
                'invoice_number' => $invoice->number,
                'tax_rate' => $taxRate,
                'tax_is_exclusive' => $taxIsExclusive,
                'tax_multiplier' => $taxMultiplier,
                'itemsTotal_exclusive' => $itemsTotal,
                'itemsTotal_inclusive' => $itemsTotalTaxInclusive,
                'salesAmount' => $salesAmount,
                'taxAmount' => $taxAmount,
                'totalAmount' => $totalAmount,
                'validation' => $itemsTotalTaxInclusive . ' ÷ 1.05 = ' . round($itemsTotalTaxInclusive / 1.05),
                'isB2B' => $isB2B,
            ]);

            // Call Amego API to issue invoice
            $response = $this->makeRequest('/json/f0401', $requestData);

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
