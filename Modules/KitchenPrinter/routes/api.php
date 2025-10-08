<?php

use Illuminate\Support\Facades\Route;
use Modules\KitchenPrinter\Http\Controllers\KitchenPrintController;

/*
|--------------------------------------------------------------------------
| API Routes for Kitchen Printer Module
|--------------------------------------------------------------------------
*/

Route::middleware(['api_db', 'token_auth', 'locale'])
    ->prefix('v1')
    ->group(function () {

        // Test printer connection
        Route::get('kitchen/test-connection', [KitchenPrintController::class, 'testConnection'])
            ->name('kitchen.test.connection');

        // Single invoice/quote print
        Route::post('invoices/{invoice}/print_kitchen', [KitchenPrintController::class, 'printInvoice'])
            ->name('kitchen.print.invoice');

        Route::post('quotes/{quote}/print_kitchen', [KitchenPrintController::class, 'printQuote'])
            ->name('kitchen.print.quote');

        // Bulk print
        Route::post('invoices/bulk_print_kitchen', [KitchenPrintController::class, 'bulkPrintInvoices'])
            ->name('kitchen.bulk.print.invoices');
    });
