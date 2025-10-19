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
            $overrideIp = $request->input('printer_ip');
            $overridePort = $request->input('printer_port');
            $port = $overridePort !== null ? (int) $overridePort : null;

            // Send to kitchen printer
            $result = $this->printerService->printInvoice($invoice, $overrideIp, $port);

            // Log the action
            Log::info('Kitchen receipt printed', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'user_id' => auth()->id(),
                'override_ip' => $overrideIp,
                'override_port' => $port,
            ]);

            return response()->json([
                'message' => 'Sent to kitchen printer',
                'invoice_number' => $invoice->number,
                'printer_ip' => $overrideIp ?? config('kitchenprinter.tcp.ip'),
                'printer_port' => $port ?? config('kitchenprinter.tcp.port'),
            ], 200);

        } catch (\Exception $e) {
            Log::error('Kitchen print failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'override_ip' => $request->input('printer_ip'),
                'override_port' => $request->input('printer_port'),
            ]);

            return response()->json([
                'message' => 'Failed to print: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * TEST: Print invoice using Star WebPRNT (direct HTTPS request)
     * Matches production formatting but targets configurable printer endpoint
     */
    public function printInvoiceWebPRNT(ShowInvoiceRequest $request, Invoice $invoice)
    {
        try {
            $overrides = [];

            if ($request->filled('printer_ip')) {
                $overrides['ip'] = $request->input('printer_ip');
            }

            if ($request->filled('printer_port')) {
                $overrides['port'] = (int) $request->input('printer_port');
            }

            if ($request->filled('printer_scheme')) {
                $overrides['scheme'] = $request->input('printer_scheme');
            }

            if ($request->filled('printer_path')) {
                $overrides['path'] = $request->input('printer_path');
            }

            if ($request->filled('printer_timeout')) {
                $overrides['timeout'] = (int) $request->input('printer_timeout');
            }

            if ($request->has('printer_verify_ssl')) {
                $verify = filter_var(
                    $request->input('printer_verify_ssl'),
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                );

                if (!is_null($verify)) {
                    $overrides['verify_ssl'] = $verify;
                }
            }

            // Send to WebPRNT service
            $result = $this->printerService->printInvoiceWebPRNT($invoice, $overrides);

            // Log the action
            Log::info('Kitchen receipt printed via WebPRNT', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'user_id' => auth()->id(),
                'webprnt_url' => $result['webprnt_url'] ?? null,
                'webprnt_config' => $result['webprnt_config'] ?? null,
                'overrides' => $overrides,
            ]);

            return response()->json([
                'message' => 'Sent to kitchen printer via WebPRNT',
                'invoice_number' => $invoice->number,
                'protocol' => 'StarWebPRNT',
                'webprnt_url' => $result['webprnt_url'] ?? null,
                'webprnt_config' => $result['webprnt_config'] ?? null,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Kitchen print failed (WebPRNT)', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'overrides' => $request->only([
                    'printer_ip',
                    'printer_port',
                    'printer_scheme',
                    'printer_path',
                    'printer_verify_ssl',
                    'printer_timeout',
                ]),
            ]);

            return response()->json([
                'message' => 'Failed to print via WebPRNT: ' . $e->getMessage(),
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

            $overrideIp = $request->input('printer_ip');
            $overridePort = $request->input('printer_port');
            $port = $overridePort !== null ? (int) $overridePort : null;

            // Send to kitchen printer
            $result = $this->printerService->printQuote($quote, $overrideIp, $port);

            Log::info('Kitchen receipt printed (quote)', [
                'quote_id' => $quote->id,
                'quote_number' => $quote->number,
                'user_id' => auth()->id(),
                'override_ip' => $overrideIp,
                'override_port' => $port,
            ]);

            return response()->json([
                'message' => 'Sent to kitchen printer',
                'quote_number' => $quote->number,
                'printer_ip' => $overrideIp ?? config('kitchenprinter.tcp.ip'),
                'printer_port' => $port ?? config('kitchenprinter.tcp.port'),
            ], 200);

        } catch (\Exception $e) {
            Log::error('Kitchen print failed (quote)', [
                'quote_id' => $quote->id,
                'error' => $e->getMessage(),
                'override_ip' => $request->input('printer_ip'),
                'override_port' => $request->input('printer_port'),
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

    public function testConnection(Request $request)
    {
        try {
            $overrideIp = $request->query('ip');
            $overridePort = $request->query('port');

            $port = $overridePort !== null ? (int) $overridePort : null;
            $connected = $this->printerService->testConnection($overrideIp, $port);

            $effectiveIp = $overrideIp ?? config('kitchenprinter.tcp.ip', '10.0.0.150');
            $effectivePort = $port ?? config('kitchenprinter.tcp.port', 9100);

            if ($connected) {
                return response()->json([
                    'message' => 'Printer connection successful',
                    'printer_ip' => $effectiveIp,
                    'printer_port' => $effectivePort,
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

    public function testPrint(Request $request)
    {
        try {
            $overrideIp = $request->query('ip');
            $overridePort = $request->query('port');

            $port = $overridePort !== null ? (int) $overridePort : null;
            $this->printerService->testPrint($overrideIp, $port);

            return response()->json([
                'message' => 'Test print sent successfully',
                'printer_ip' => $overrideIp ?? config('kitchenprinter.tcp.ip', '10.0.0.150'),
                'printer_port' => $port ?? config('kitchenprinter.tcp.port', 9100),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Test print failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
