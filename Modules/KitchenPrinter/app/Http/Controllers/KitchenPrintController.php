<?php

namespace Modules\KitchenPrinter\Http\Controllers;

use App\Models\Invoice;
use App\Models\Quote;
use App\Http\Controllers\Controller;
use Modules\KitchenPrinter\Services\CloudPRNTService;
use Modules\KitchenPrinter\Services\KitchenPrintFormatter;
use Modules\KitchenPrinter\Http\Requests\PrintKitchenRequest;
use Illuminate\Support\Facades\Log;

class KitchenPrintController extends Controller
{
    protected CloudPRNTService $cloudPRNT;
    protected KitchenPrintFormatter $formatter;

    public function __construct(
        CloudPRNTService $cloudPRNT,
        KitchenPrintFormatter $formatter
    ) {
        $this->cloudPRNT = $cloudPRNT;
        $this->formatter = $formatter;
    }

    public function printInvoice(PrintKitchenRequest $request, Invoice $invoice)
    {
        try {
            // Check permissions
            if (!auth()->user()->can('view', $invoice)) {
                return response()->json([
                    'message' => 'Unauthorized to print this invoice',
                ], 403);
            }

            // Format invoice data for kitchen receipt
            $receiptData = $this->formatter->formatInvoice($invoice);

            // Send to printer via CloudPRNT
            $result = $this->cloudPRNT->print($receiptData);

            // Log the print action
            Log::info('Kitchen receipt printed', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'user_id' => auth()->id(),
                'job_id' => $result['job_id'] ?? null,
            ]);

            return response()->json([
                'message' => 'Kitchen receipt sent successfully',
                'invoice_number' => $invoice->number,
                'print_job_id' => $result['job_id'] ?? null,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Kitchen print failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to print kitchen receipt: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function printQuote(PrintKitchenRequest $request, Quote $quote)
    {
        try {
            // Check permissions
            if (!auth()->user()->can('view', $quote)) {
                return response()->json([
                    'message' => 'Unauthorized to print this quote',
                ], 403);
            }

            // Format quote data for kitchen receipt
            $receiptData = $this->formatter->formatQuote($quote);

            // Send to printer via CloudPRNT
            $result = $this->cloudPRNT->print($receiptData);

            // Log the print action
            Log::info('Kitchen receipt printed (quote)', [
                'quote_id' => $quote->id,
                'quote_number' => $quote->number,
                'user_id' => auth()->id(),
                'job_id' => $result['job_id'] ?? null,
            ]);

            return response()->json([
                'message' => 'Kitchen receipt sent successfully',
                'quote_number' => $quote->number,
                'print_job_id' => $result['job_id'] ?? null,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Kitchen print failed (quote)', [
                'quote_id' => $quote->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to print kitchen receipt: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function bulkPrintInvoices(PrintKitchenRequest $request)
    {
        $invoice_ids = $request->input('ids', []);
        
        if (empty($invoice_ids)) {
            return response()->json(['message' => 'No invoice IDs provided'], 400);
        }

        $invoices = Invoice::whereIn('id', $invoice_ids)->get();
        
        $results = [];
        $successCount = 0;
        $failCount = 0;

        foreach ($invoices as $invoice) {
            if (!auth()->user()->can('view', $invoice)) {
                $results[] = [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'status' => 'failed',
                    'error' => 'Unauthorized',
                ];
                $failCount++;
                continue;
            }

            try {
                $receiptData = $this->formatter->formatInvoice($invoice);
                $result = $this->cloudPRNT->print($receiptData);
                
                $results[] = [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'status' => 'success',
                    'job_id' => $result['job_id'] ?? null,
                ];
                $successCount++;
            } catch (\Exception $e) {
                $results[] = [
                    'id' => $invoice->id,
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
            $connected = $this->cloudPRNT->testConnection();

            if ($connected) {
                return response()->json([
                    'message' => 'Printer connection successful',
                    'printer_ip' => config('kitchenprinter.tcp.ip'),
                    'printer_port' => config('kitchenprinter.tcp.port'),
                ], 200);
            } else {
                return response()->json([
                    'message' => 'Could not connect to printer',
                    'printer_ip' => config('kitchenprinter.tcp.ip'),
                    'printer_port' => config('kitchenprinter.tcp.port'),
                ], 500);
            }
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Printer connection failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function testPrint()
    {
        try {
            // Create simple test receipt
            $markup = "[magnify: width 2; height 2]\n";
            $markup .= "[align: center]\n";
            $markup .= "*** TEST PRINT ***\n";
            $markup .= "[magnify: width 1; height 1]\n";
            $markup .= "[align: left]\n\n";
            $markup .= "Invoice Ninja Kitchen Printer\n";
            $markup .= "TCP/IP Direct Printing\n";
            $markup .= "IP: " . config('kitchenprinter.tcp.ip') . "\n";
            $markup .= "Port: " . config('kitchenprinter.tcp.port') . "\n";
            $markup .= "Time: " . now()->format('Y-m-d H:i:s') . "\n";
            $markup .= "\n";
            $markup .= "If you see this, printing works!\n";
            $markup .= "\n\n\n";
            $markup .= "[cut: feed; partial]\n";

            $result = $this->cloudPRNT->print($markup);

            return response()->json([
                'message' => 'Test print sent successfully',
                'job_id' => $result['job_id'] ?? null,
                'printer_ip' => config('kitchenprinter.tcp.ip'),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Test print failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
