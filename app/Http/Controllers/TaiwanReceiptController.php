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
 */
class TaiwanReceiptController extends BaseController
{
    protected TaiwanEInvoiceService $service;

    public function __construct()
    {
        $this->service = new TaiwanEInvoiceService();
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
        // Get optional email from request
        $einvoiceEmail = $request->input('einvoice_email');

        // Call service to issue receipt
        $result = $this->service->issueReceipt($payment, $einvoiceEmail);

        // Return appropriate response
        if ($result['success']) {
            return response()->json([
                'success' => true,
                'receipt_number' => $result['receipt_number'],
                'message' => $result['message'],
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

        // Call service to void receipt
        $result = $this->service->voidReceipt($payment, $reason);

        // Return appropriate response
        if ($result['success']) {
            return response()->json([
                'success' => true,
                'message' => $result['message'],
            ], 200);
        } else {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 400);
        }
    }
}
