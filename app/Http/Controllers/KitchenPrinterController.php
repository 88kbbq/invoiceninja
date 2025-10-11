<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Quote;
use App\Services\KitchenPrinterService;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Invoice\ShowInvoiceRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class KitchenPrinterController extends BaseController
{
    private KitchenPrinterService $printerService;

    public function __construct(KitchenPrinterService $printerService)
    {
        parent::__construct();
        $this->printerService = $printerService;
    }

    public function printInvoice(ShowInvoiceRequest $request, Invoice $invoice)
    {
        try {
            // Send to kitchen printer
            $result = $this->printerService->printInvoice($invoice);

            // Log the action
            Log::info('Kitchen receipt printed', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'message' => 'Sent to kitchen printer',
                'invoice_number' => $invoice->number,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Kitchen print failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to print: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function printQuote(Request $request, Quote $quote)
    {
        try {
            // Check permissions
            if (!auth()->user()->can('view', $quote)) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            // Send to kitchen printer
            $result = $this->printerService->printQuote($quote);

            Log::info('Kitchen receipt printed (quote)', [
                'quote_id' => $quote->id,
                'quote_number' => $quote->number,
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'message' => 'Sent to kitchen printer',
                'quote_number' => $quote->number,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Kitchen print failed (quote)', [
                'quote_id' => $quote->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to print: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function bulkPrintInvoices(Request $request)
    {
        $invoice_ids = $request->input('ids', []);

        if (empty($invoice_ids)) {
            return response()->json(['message' => 'No invoice IDs provided'], 400);
        }

        $invoices = Invoice::whereIn('id', $invoice_ids)
            ->where('company_id', auth()->user()->company()->id)
            ->get();

        $results = [];
        $successCount = 0;
        $failCount = 0;

        foreach ($invoices as $invoice) {
            if (!auth()->user()->can('view', $invoice)) {
                $results[] = [
                    'id' => $invoice->hashed_id,
                    'number' => $invoice->number,
                    'status' => 'failed',
                    'error' => 'Unauthorized',
                ];
                $failCount++;
                continue;
            }

            try {
                $this->printerService->printInvoice($invoice);

                $results[] = [
                    'id' => $invoice->hashed_id,
                    'number' => $invoice->number,
                    'status' => 'success',
                ];
                $successCount++;
            } catch (\Exception $e) {
                $results[] = [
                    'id' => $invoice->hashed_id,
                    'number' => $invoice->number,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
                $failCount++;
            }
        }

        return response()->json([
            'message' => "Printed {$successCount} of " . count($invoices) . " invoices",
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'results' => $results,
        ], 200);
    }

    public function testConnection()
    {
        try {
            $connected = $this->printerService->testConnection();

            if ($connected) {
                return response()->json([
                    'message' => 'Printer connection successful',
                    'printer_ip' => config('kitchenprinter.tcp.ip', '10.0.0.150'),
                    'printer_port' => config('kitchenprinter.tcp.port', 9100),
                ], 200);
            } else {
                return response()->json([
                    'message' => 'Could not connect to printer',
                ], 500);
            }
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Connection failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function testPrint()
    {
        try {
            $this->printerService->testPrint();

            return response()->json([
                'message' => 'Test print sent successfully',
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Test print failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}