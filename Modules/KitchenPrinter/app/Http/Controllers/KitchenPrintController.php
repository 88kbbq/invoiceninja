<?php

namespace Modules\KitchenPrinter\Http\Controllers;

use App\Models\Invoice;
use App\Models\Quote;
use App\Http\Controllers\Controller;
use Modules\KitchenPrinter\Services\CloudPRNTService;
use Modules\KitchenPrinter\Services\KitchenPrintFormatter;
use Modules\KitchenPrinter\Http\Requests\PrintKitchenRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

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
                    'printer_url' => config('kitchenprinter.cloudprnt.url'),
                ], 200);
            } else {
                return response()->json([
                    'message' => 'Could not connect to printer',
                    'printer_url' => config('kitchenprinter.cloudprnt.url'),
                ], 500);
            }
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Printer connection failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * CloudPRNT polling endpoint - printer polls this to get print jobs
     */
    public function cloudprntPoll(Request $request)
    {
        // Get printer MAC address from request
        $mac = $request->header('X-Star-Mac') ?? config('kitchenprinter.cloudprnt.mac_address');

        // Log printer poll
        Log::debug('CloudPRNT poll', [
            'mac' => $mac,
            'user_agent' => $request->userAgent(),
        ]);

        // Check if there are pending print jobs in cache
        $jobData = Cache::get("cloudprnt_job_{$mac}");

        if ($jobData) {
            // Remove job from cache
            Cache::forget("cloudprnt_job_{$mac}");

            // Return print job
            return response($jobData)
                ->header('Content-Type', 'text/plain; charset=utf-8')
                ->header('Content-Disposition', 'inline; filename="job.stm"');
        }

        // No jobs available
        return response('', 204);
    }

    /**
     * CloudPRNT status endpoint - printer reports job status
     */
    public function cloudprntStatus(Request $request)
    {
        $mac = $request->header('X-Star-Mac') ?? config('kitchenprinter.cloudprnt.mac_address');
        $statusCode = $request->input('code');
        $jobId = $request->input('jobToken');

        Log::info('CloudPRNT status update', [
            'mac' => $mac,
            'status_code' => $statusCode,
            'job_id' => $jobId,
        ]);

        return response('', 200);
    }

    /**
     * Queue a test print job (for testing)
     */
    public function queueTestJob(Request $request)
    {
        $mac = config('kitchenprinter.cloudprnt.mac_address');

        // Create simple Star Document Markup test
        $markup = "[magnify: width 2; height 2]\n";
        $markup .= "[align: center]\n";
        $markup .= "HELLO WORLD\n";
        $markup .= "[magnify: width 1; height 1]\n";
        $markup .= "[align: left]\n";
        $markup .= "Test print from Invoice Ninja\n";
        $markup .= now()->format('Y-m-d H:i:s') . "\n\n\n";
        $markup .= "[cut: feed; partial]\n";

        // Queue the job
        Cache::put("cloudprnt_job_{$mac}", $markup, now()->addMinutes(5));

        Log::info('Test print job queued', ['mac' => $mac]);

        return response()->json([
            'message' => 'Test job queued. Printer should pick it up on next poll.',
            'mac' => $mac,
        ]);
    }
}
