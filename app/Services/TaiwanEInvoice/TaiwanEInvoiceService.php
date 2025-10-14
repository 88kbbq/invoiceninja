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
            throw new Exception($responseData['msg'] ?? 'Unknown API error');
        }

        return $responseData;
    }

    /**
     * Issue Taiwan E-Invoice
     *
     * @param Payment $payment
     * @param string|null $einvoiceEmail Additional email from form
     * @return array ['success' => bool, 'receipt_number' => string, 'message' => string]
     */
    public function issueReceipt(Payment $payment, ?string $einvoiceEmail = null): array
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

            // Check for GUI number (統一編號) in invoice custom_value2
            $buyerGui = $invoice->custom_value2 ?? '';
            $isB2B = !empty($buyerGui) && strlen($buyerGui) === 8;

            // Prepare line items
            // IMPORTANT: ProductItem amounts are TAX-INCLUSIVE (as-is from invoice)
            $productItems = [];
            $itemsTotal = 0;

            foreach ($invoice->line_items as $item) {
                $quantity = (float) $item->quantity;
                $unitPrice = (int) round((float) $item->cost); // Tax-inclusive price from invoice

                // Calculate amount - MUST equal Quantity * UnitPrice for API validation
                $amount = (int) round($quantity * $unitPrice);
                $itemsTotal += $amount;

                $productItems[] = [
                    'Description' => $item->product_key ?: $item->notes ?: '服務費',
                    'Quantity' => (string) $quantity,      // Must be string per API docs
                    'Unit' => '式',
                    'UnitPrice' => (string) $unitPrice,    // Must be string per API docs
                    'Amount' => (string) $amount,          // Must be string per API docs
                    'Remark' => '',
                    'TaxType' => '1', // 應稅 (must be string per API docs)
                ];
            }

            // Calculate amounts based on B2C vs B2B
            // Sum of all ProductItem amounts (tax-inclusive)
            $sum = $itemsTotal;

            if ($isB2B) {
                // B2B (with GUI): Extract tax from tax-inclusive amount
                // Example: Sum=168 → Round(168/1.05)=160 → TaxAmount=168-160=8, SalesAmount=160
                $salesAmountBeforeTax = (int) round($sum / 1.05);
                $taxAmount = $sum - $salesAmountBeforeTax;
                $salesAmount = $salesAmountBeforeTax;
                $totalAmount = $sum;
            } else {
                // B2C (no GUI): No tax separation
                // Example: Sum=168 → SalesAmount=168, TaxAmount=0, TotalAmount=168
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
                'SalesAmount' => (string) $salesAmount,           // Must be string per API docs
                'FreeTaxSalesAmount' => '0',                      // Must be string per API docs
                'ZeroTaxSalesAmount' => '0',                      // Must be string per API docs
                'TaxType' => '1',
                'TaxRate' => '0.05',
                'TaxAmount' => (string) $taxAmount,               // Must be string per API docs
                'TotalAmount' => (string) $totalAmount,           // Must be string per API docs
            ];

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
