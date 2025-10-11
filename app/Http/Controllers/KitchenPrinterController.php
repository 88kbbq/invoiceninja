<?php

namespace App\Http\Controllers;

use App\Http\Controllers\BaseController;
use App\Models\Invoice;
use App\Services\KitchenPrinterService;
use Illuminate\Http\Request;

class KitchenPrinterController extends BaseController
{
    private $printerService;

    public function __construct()
    {
        parent::__construct();
        $this->printerService = new KitchenPrinterService();
    }

    /**
     * Test printer connection
     */
    public function testConnection()
    {
        return response()->json($this->printerService->testConnection());
    }

    /**
     * Send test print
     */
    public function testPrint()
    {
        return response()->json($this->printerService->printTestReceipt());
    }

    /**
     * Print invoice to kitchen printer
     */
    public function print(Request $request, $invoiceId)
    {
        $invoice = Invoice::with(['client', 'line_items'])->findOrFail($invoiceId);

        // Check permissions
        $this->authorize('view', $invoice);

        $result = $this->printerService->printInvoice($invoice);

        return response()->json($result);
    }
}