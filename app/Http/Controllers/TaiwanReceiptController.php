<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\TaiwanEInvoice\TaiwanEInvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Taiwan E-Invoice Receipt Controller
 *
 * Handles issuing and voiding Taiwan government e-invoices via Amego API
 *
 * Supports multiple issuing companies:
 * - benfire: 犇火燻寶有限公司 - Used for TapPay (credit card) payments
 * - bameixin: 霸美燻王有限公司 - Available for manual payments only
 */
class TaiwanReceiptController extends BaseController
{
    /**
     * Determine if payment is a TapPay (credit card) transaction
     * TapPay transactions have a transaction_reference that is NOT 'Manual entry'
     */
    protected function isTapPayPayment(Payment $payment): bool
    {
        $txnRef = $payment->transaction_reference ?? '';
        return !empty($txnRef) && $txnRef !== 'Manual entry';
    }

    /**
     * Determine which company should issue the e-invoice
     *
     * Rules:
     * - TapPay payments: MUST use 'benfire' (犇火燻寶有限公司)
     * - Manual payments: User can choose, defaults to 'benfire'
     */
    protected function determineIssuingCompany(Payment $payment, ?string $requestedCompany): string
    {
        // TapPay payments always use benfire
        if ($this->isTapPayPayment($payment)) {
            return 'benfire';
        }

        // Manual payments can use requested company, default to benfire
        $validCompanies = ['benfire', 'bameixin'];
        if ($requestedCompany && in_array($requestedCompany, $validCompanies)) {
            return $requestedCompany;
        }

        return 'benfire';
    }

    /**
     * Issue Taiwan E-Invoice
     *
     * POST /api/v1/payments/{payment}/taiwan_receipt
     *
     * @param Request $request
     * @param Payment $payment
     * @return JsonResponse
     */
    public function issue(Request $request, Payment $payment): JsonResponse
    {
        // Get optional parameters from request
        $einvoiceEmail = $request->input('einvoice_email');
        $buyerGui = $request->input('buyer_gui'); // VAT/GUI number (統一編號)
        $requestedCompany = $request->input('issuing_company'); // 'benfire' or 'bameixin'

        // Determine which company issues this e-invoice
        $companyCode = $this->determineIssuingCompany($payment, $requestedCompany);

        // Create service with appropriate company credentials
        $service = new TaiwanEInvoiceService($companyCode);

        // Call service to issue receipt
        $result = $service->issueReceipt($payment, $einvoiceEmail, $buyerGui);

        // Return appropriate response
        if ($result['success']) {
            return response()->json([
                'success' => true,
                'receipt_number' => $result['receipt_number'],
                'message' => $result['message'],
                'issuing_company' => $companyCode,
                'issuing_company_name' => $service->getCompanyName(),
            ], 200);
        } else {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 400);
        }
    }

    /**
     * Void Taiwan E-Invoice
     *
     * DELETE /api/v1/payments/{payment}/taiwan_receipt
     *
     * Uses the company code stored in custom_value4 to ensure voiding
     * uses the same company credentials that issued the e-invoice.
     *
     * @param Request $request
     * @param Payment $payment
     * @return JsonResponse
     */
    public function void(Request $request, Payment $payment): JsonResponse
    {
        // Require reason parameter
        $reason = $request->input('reason');
        if (empty($reason)) {
            return response()->json([
                'success' => false,
                'message' => 'Void reason is required',
            ], 400);
        }

        // Get the company that issued this e-invoice from custom_value4
        // custom_value4 can contain either:
        // - Company name (犇火燻寶有限公司 or 霸美燻王有限公司) - new format
        // - Company code (benfire or bameixin) - legacy format
        // Default to 'benfire' for invoices without company info
        $storedValue = $payment->custom_value4 ?: '';
        $companyCode = 'benfire'; // default

        if (!empty($storedValue)) {
            // Check if it's a company name (new format)
            if (TaiwanEInvoiceService::isValidCompanyName($storedValue)) {
                $companyCode = TaiwanEInvoiceService::getCompanyCodeFromName($storedValue);
            }
            // Check if it's a company code (legacy format)
            elseif (TaiwanEInvoiceService::isValidCompanyCode($storedValue)) {
                $companyCode = $storedValue;
            }
            else {
                \Log::warning('Unknown company value in custom_value4, defaulting to benfire', [
                    'payment_id' => $payment->id,
                    'custom_value4' => $storedValue,
                ]);
            }
        }

        // Create service with the same company that issued the e-invoice
        $service = new TaiwanEInvoiceService($companyCode);

        // Call service to void receipt
        $result = $service->voidReceipt($payment, $reason);

        // Return appropriate response
        if ($result['success']) {
            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'voided_by_company' => $companyCode,
                'voided_by_company_name' => $service->getCompanyName(),
            ], 200);
        } else {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 400);
        }
    }

    /**
     * Check if payment is a manual entry (for frontend to determine UI)
     *
     * GET /api/v1/payments/{payment}/taiwan_receipt/payment_type
     *
     * @param Payment $payment
     * @return JsonResponse
     */
    public function getPaymentType(Payment $payment): JsonResponse
    {
        $isTapPay = $this->isTapPayPayment($payment);

        return response()->json([
            'is_tappay' => $isTapPay,
            'is_manual' => !$isTapPay,
            'transaction_reference' => $payment->transaction_reference,
            'allow_company_selection' => !$isTapPay,
        ], 200);
    }
}
