<?php

use Illuminate\Support\Facades\Route;
use Modules\KitchenPrinter\Http\Controllers\KitchenPrintController;

/*
|--------------------------------------------------------------------------
| API Routes for Kitchen Printer Module
|--------------------------------------------------------------------------
| Note: 'api' middleware and '/api' prefix are already added by RouteServiceProvider
*/

// CloudPRNT endpoint (no auth - printer polls this)
Route::prefix('v1')->group(function () {
    Route::get('kitchen/cloudprnt', [KitchenPrintController::class, 'cloudprntPoll'])
        ->name('kitchen.cloudprnt.poll');
    Route::post('kitchen/cloudprnt', [KitchenPrintController::class, 'cloudprntStatus'])
        ->name('kitchen.cloudprnt.status');
    Route::get('kitchen/test-job', [KitchenPrintController::class, 'queueTestJob'])
        ->name('kitchen.test.job');
});

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
