<?php

namespace App\Http\Controllers;

use App\Http\Requests\Invoice\ShowInvoiceRequest;
use App\Models\Invoice;
use App\Models\Quote;
use App\Services\KitchenPrinter\KitchenPrinterManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class KitchenPrinterController extends BaseController
{
    public function __construct(private readonly KitchenPrinterManager $printerManager)
    {
        parent::__construct();
    }

    public function printInvoice(ShowInvoiceRequest $request, Invoice $invoice): Response
    {
        $overrides = $this->buildOverrides($request);

        try {
            $result = $this->printerManager->printInvoice($invoice, $overrides);

            Log::info('Kitchen receipt printed', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'user_id' => auth()->id(),
                'transport' => $result['transport'] ?? null,
            ]);

            return response()->json($this->buildSuccessPayload(
                'Sent to kitchen printer',
                $result
            ));
        } catch (\Throwable $e) {
            Log::error('Kitchen print failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'overrides' => $overrides,
            ]);

            return $this->errorResponse('Failed to print: ' . $e->getMessage());
        }
    }

    public function printInvoiceWebPRNT(ShowInvoiceRequest $request, Invoice $invoice): Response
    {
        $overrides = $this->buildOverrides($request) + ['transport' => 'webprnt'];

        try {
            $result = $this->printerManager->printInvoice($invoice, $overrides);

            Log::info('Kitchen receipt printed via WebPRNT', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'user_id' => auth()->id(),
                'transport' => $result['transport'] ?? null,
                'url' => $result['url'] ?? null,
            ]);

            return response()->json($this->buildSuccessPayload(
                'Sent to kitchen printer via WebPRNT',
                $result
            ));
        } catch (\Throwable $e) {
            Log::error('Kitchen print failed (WebPRNT)', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'overrides' => $overrides,
            ]);

            return $this->errorResponse('Failed to print via WebPRNT: ' . $e->getMessage());
        }
    }

    public function printQuote(Request $request, Quote $quote): Response
    {
        if (!auth()->user()->can('view', $quote)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $overrides = $this->buildOverrides($request);

        try {
            $result = $this->printerManager->printQuote($quote, $overrides);

            Log::info('Kitchen receipt printed (quote)', [
                'quote_id' => $quote->id,
                'quote_number' => $quote->number,
                'user_id' => auth()->id(),
                'transport' => $result['transport'] ?? null,
            ]);

            return response()->json($this->buildSuccessPayload(
                'Sent to kitchen printer',
                $result
            ));
        } catch (\Throwable $e) {
            Log::error('Kitchen print failed (quote)', [
                'quote_id' => $quote->id,
                'error' => $e->getMessage(),
                'overrides' => $overrides,
            ]);

            return $this->errorResponse('Failed to print: ' . $e->getMessage());
        }
    }

    public function bulkPrintInvoices(Request $request): Response
    {
        $ids = (array) $request->input('ids', []);

        if (empty($ids)) {
            return response()->json(['message' => 'No invoice IDs provided'], 400);
        }

        $companyId = auth()->user()->company()->id;
        $invoices = Invoice::whereIn('id', $ids)
            ->where('company_id', $companyId)
            ->get();

        $overrides = $this->buildOverrides($request);
        $results = [];
        $errors = [];

        foreach ($invoices as $invoice) {
            try {
                $results[] = $this->printerManager->printInvoice($invoice, $overrides);
            } catch (\Throwable $e) {
                Log::error('Kitchen bulk print failed', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);

                $errors[] = [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->number,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => empty($errors) ? 'All invoices sent to kitchen printer' : 'Some invoices failed to print',
            'success_count' => count($results),
            'error_count' => count($errors),
            'errors' => $errors,
        ], empty($errors) ? 200 : 207);
    }

    public function testConnection(Request $request): Response
    {
        $overrides = $this->buildOverrides($request);
        $overrides['transport'] = $overrides['transport'] ?? $request->input('transport');

        $reachable = $this->printerManager->testConnection($overrides);

        return response()->json([
            'message' => $reachable ? 'Connection successful' : 'Connection failed',
            'reachable' => $reachable,
        ], $reachable ? 200 : 503);
    }

    public function testPrint(Request $request): Response
    {
        try {
            $result = $this->printerManager->testPrint($this->buildOverrides($request));

            return response()->json($this->buildSuccessPayload(
                'Test ticket sent to kitchen printer',
                $result
            ));
        } catch (\Throwable $e) {
            return $this->errorResponse('Failed to send test print: ' . $e->getMessage());
        }
    }

    /**
     * Collect supported override parameters from the request.
     */
    protected function buildOverrides(Request $request): array
    {
        $overrides = [];

        foreach ([
            'transport',
            'printer_ip' => 'ip',
            'printer_host' => 'host',
            'printer_port' => 'port',
            'printer_scheme' => 'scheme',
            'printer_path' => 'path',
            'printer_timeout' => 'timeout',
            'printer_username' => 'username',
            'printer_password' => 'password',
        ] as $input => $key) {
            if (is_int($input)) {
                $input = $key;
            }

            if ($request->filled($input)) {
                $overrides[$key] = $request->input($input);
            }
        }

        if ($request->has('printer_verify_ssl')) {
            $value = filter_var(
                $request->input('printer_verify_ssl'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );

            if ($value !== null) {
                $overrides['verify_ssl'] = $value;
            }
        }

        if (isset($overrides['port'])) {
            $overrides['port'] = (int) $overrides['port'];
        }

        if (isset($overrides['timeout'])) {
            $overrides['timeout'] = (int) $overrides['timeout'];
        }

        return $overrides;
    }

    protected function buildSuccessPayload(string $message, array $result): array
    {
        $details = $result;
        unset($details['success']);

        return [
            'message' => $message,
            'success' => $result['success'] ?? false,
            'transport' => $result['transport'] ?? null,
            'details' => $details,
        ];
    }

    protected function errorResponse(string $message): Response
    {
        return response()->json([
            'message' => $message,
        ], 500);
    }
}
