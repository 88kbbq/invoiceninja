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
 */
class TaiwanEInvoiceService
{
    protected string $apiUrl;
    protected string $appKey;
    protected string $companyGui;
    protected bool $testMode;

    public function __construct()
    {
        $this->apiUrl = 'https://invoice-api.amego.tw';
        $this->testMode = config('app.env') !== 'production' || env('TAIWAN_EINVOICE_TEST_MODE', true);

        if ($this->testMode) {
            // Test credentials from Amego documentation
            $this->companyGui = '12345678';
            $this->appKey = 'sHeq7t8G1wiQvhAuIM27';
        } else {
            // Production credentials from environment
            $this->companyGui = env('TAIWAN_COMPANY_GUI', '83464574');
            $this->appKey = env('TAIWAN_EINVOICE_APP_KEY', '');
        }
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

            // Prepare line items
            $productItems = [];
            $itemsTotal = 0;

            foreach ($invoice->line_items as $item) {
                $quantity = (float) $item->quantity;
                $unitPrice = (int) round((float) $item->cost);

                // Calculate amount - MUST equal Quantity * UnitPrice for API validation
                $amount = (int) round($quantity * $unitPrice);
                $itemsTotal += $amount;

                $productItems[] = [
                    'Description' => $item->product_key ?: $item->notes ?: '服務費',
                    'Quantity' => $quantity,               // Number per API docs
                    'Unit' => '式',
                    'UnitPrice' => $unitPrice,             // Number per API docs
                    'Amount' => $amount,                   // Number per API docs
                    'Remark' => '',
                    'TaxType' => 1,                        // Number 1=應稅 per API docs
                ];
            }

            // Calculate total amount including tax
            // Invoice Ninja may calculate tax at invoice level (uses_inclusive_taxes = false)
            // or include it in line item costs (uses_inclusive_taxes = true)
            if ($invoice->uses_inclusive_taxes) {
                // Tax already included in line item costs
                $sum = $itemsTotal;
            } else {
                // Tax calculated separately at invoice level - must add it
                $sum = $itemsTotal + (int) round($invoice->total_taxes);
            }

            if ($isB2B) {
                // B2B (with GUI): SalesAmount = sum of ProductItems, TaxAmount calculated from rate
                // IMPORTANT: SalesAmount MUST equal sum of ProductItem.Amount values for API validation
                // Example: itemsTotal=44660 → TaxAmount=2233 → TotalAmount=46893
                $salesAmount = $itemsTotal;
                $taxAmount = $sum - $itemsTotal;
                $totalAmount = $sum;
            } else {
                // B2C (no GUI): No tax separation
                // Example: Sum=46893 → SalesAmount=46893, TaxAmount=0, TotalAmount=46893
                $salesAmount = $sum;
                $taxAmount = 0;
                $totalAmount = $sum;
            }

            // Build API request data
            $requestData = [
                'OrderId' => 'INV-' . $invoice->number,
                'BuyerIdentifier' => $isB2B ? $buyerGui : '0000000000',
                'BuyerName' => $isB2B ? $client->name : '客人',
                'BuyerAddress' => $client->address1 ?? '',
                'BuyerTelephoneNumber' => '', // Do not pass phone (SMS fees)
                'BuyerEmailAddress' => $buyerEmailAddress,
                'MainRemark' => $invoice->public_notes ?? '',
                'CarrierType' => '', // No carrier
                'CarrierId1' => '',
                'CarrierId2' => '',
                'NPOBAN' => '', // No donation
                'ProductItem' => $productItems,
                'SalesAmount' => $salesAmount,                    // Number per API docs
                'FreeTaxSalesAmount' => 0,                        // Number per API docs
                'ZeroTaxSalesAmount' => 0,                        // Number per API docs
                'TaxType' => 1,                                   // Number 1=應稅 per API docs
                'TaxRate' => '0.05',                              // String per API docs (ONLY numeric field as string!)
                'TaxAmount' => $taxAmount,                        // Number per API docs
                'TotalAmount' => $totalAmount,                    // Number per API docs
            ];

            // Log request data for debugging
            \Log::info('Taiwan E-Invoice API Request', [
                'invoice_number' => $invoice->number,
                'itemsTotal' => $itemsTotal,
                'sum' => $sum,
                'salesAmount' => $salesAmount,
                'taxAmount' => $taxAmount,
                'totalAmount' => $totalAmount,
                'productItems' => $productItems,
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
            $payment->save();

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

            // Clear payment receipt data
            $payment->custom_value1 = '';
            $payment->custom_value2 = '';
            $payment->custom_value3 = '';
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
