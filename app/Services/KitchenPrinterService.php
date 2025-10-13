<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Quote;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

class KitchenPrinterService
{
    private string $printerIp;
    private int $printerPort;
    private bool $enabled;

    public function __construct()
    {
        $this->printerIp = config('kitchenprinter.tcp.ip', '192.168.50.39');
        $this->printerPort = config('kitchenprinter.tcp.port', 9100);
        $this->enabled = config('kitchenprinter.enabled', false);
    }

    public function printInvoice(Invoice $invoice): bool
    {
        if (!$this->enabled) {
            throw new Exception('Kitchen printer is disabled');
        }

        $receiptData = $this->formatInvoiceReceipt($invoice);
        return $this->sendToPrinter($receiptData);
    }

    public function printQuote(Quote $quote): bool
    {
        if (!$this->enabled) {
            throw new Exception('Kitchen printer is disabled');
        }

        $receiptData = $this->formatQuoteReceipt($quote);
        return $this->sendToPrinter($receiptData);
    }

    public function testConnection(): bool
    {
        try {
            $socket = @fsockopen($this->printerIp, $this->printerPort, $errno, $errstr, 2);
            if ($socket) {
                fclose($socket);
                return true;
            }
            return false;
        } catch (Exception $e) {
            Log::error('Printer connection test failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function testPrint(): bool
    {
        $testReceipt = $this->getTestReceipt();
        return $this->sendToPrinter($testReceipt);
    }

    private function formatInvoiceReceipt(Invoice $invoice): string
    {
        $client = $invoice->client;
        $lineItems = $invoice->line_items;

        // Start building receipt with proper Big5 encoding
        $receipt = "";

        // Initialize printer
        $receipt .= chr(27) . chr(64); // ESC @ - Initialize printer

        // Set double size for entire receipt (ESC ! 48 = double width + height)
        $receipt .= chr(27) . chr(33) . chr(48);

        // Helper function to convert UTF-8 to Big5
        $toBig5 = function($text) {
            return iconv('UTF-8', 'BIG-5//IGNORE', $text);
        };

        // Date and time in Chinese format
        $receipt .= $toBig5(Carbon::parse($invoice->date)->format('Y-m-d')) . "\n";

        // Event time from custom fields (if exists)
        if ($invoice->custom_value1) {
            $receipt .= $toBig5($invoice->custom_value1) . "\n";
        }

        // Client name
        $receipt .= $toBig5($client->present()->name()) . "\n";

        // Client phone
        if ($client->phone) {
            $receipt .= $toBig5($client->phone) . "\n";
        }

        // Separator line
        $receipt .= str_repeat("-", 20) . "\n";

        // Line items (no prices for kitchen)
        foreach ($lineItems as $item) {
            if (empty($item->product_key) && empty($item->notes)) {
                continue;
            }

            // Quantity
            $qty = number_format($item->quantity, 0);

            // Item name - use product_key only
            $itemName = $item->product_key ?: $item->notes;

            // Format: "Item name    qty" with quantity right-aligned
            $receipt .= $toBig5(str_pad($itemName, 17, " ", STR_PAD_RIGHT) . str_pad($qty, 3, " ", STR_PAD_LEFT)) . "\n";
        }

        // Separator line
        $receipt .= str_repeat("-", 20) . "\n";

        // Public notes (only if not empty)
        if (!empty($invoice->public_notes)) {
            $receipt .= $toBig5($invoice->public_notes) . "\n";
        }

        // Private notes (only if not empty)
        if (!empty($invoice->private_notes)) {
            $receipt .= $toBig5($invoice->private_notes) . "\n";
        }

        // Feed and cut (StarPRNT: ESC d)
        $receipt .= "\n\n\n";
        $receipt .= chr(27) . chr(100) . chr(1); // ESC d 1 - Partial cut

        return $receipt;
    }

    private function formatQuoteReceipt(Quote $quote): string
    {
        $client = $quote->client;
        $lineItems = $quote->line_items;

        // Start building receipt with StarPRNT commands
        $receipt = "";

        // Initialize printer
        $receipt .= chr(27) . chr(64); // ESC @ - Initialize printer

        // Enable Traditional Chinese Big5 encoding
        $receipt .= chr(27) . chr(36); // ESC $ - Enable Kanji/Chinese character mode

        // Quote details
        $receipt .= $quote->number . "\n";
        $receipt .= "Date: " . Carbon::parse($quote->date)->format('Y-m-d H:i') . "\n";
        $receipt .= "\n";

        // Client info
        $receipt .= chr(27) . chr(69); // ESC E - Emphasized on
        $receipt .= "Customer: " . $client->present()->name() . "\n";
        $receipt .= chr(27) . chr(70); // ESC F - Emphasized off

        if ($client->phone) {
            $receipt .= "Phone: " . $client->phone . "\n";
        }

        $receipt .= str_repeat("-", 40) . "\n";

        // Line items
        $receipt .= chr(27) . chr(69); // ESC E - Emphasized on
        $receipt .= "ITEMS:\n";
        $receipt .= chr(27) . chr(70); // ESC F - Emphasized off
        $receipt .= str_repeat("-", 40) . "\n";

        foreach ($lineItems as $item) {
            if (empty($item->product_key) && empty($item->notes)) {
                continue;
            }

            // Quantity
            $qty = number_format($item->quantity, 0);
            $receipt .= str_pad($qty . "x", 5, " ", STR_PAD_RIGHT);

            // Item description
            $description = $item->product_key ?: $item->notes;
            $receipt .= $description . "\n";

            // Add notes if different from product key
            if ($item->notes && $item->notes != $item->product_key) {
                $receipt .= "     " . $item->notes . "\n";
            }
        }

        $receipt .= str_repeat("-", 40) . "\n";

        // Footer
        $receipt .= "\n";
        $receipt .= chr(27) . chr(29) . chr(97) . chr(1); // ESC GS a 1 - Center alignment
        $receipt .= "Time: " . now()->format('H:i:s') . "\n";
        $receipt .= chr(27) . chr(29) . chr(97) . chr(0); // ESC GS a 0 - Left alignment

        // Feed and cut (StarPRNT: ESC d)
        $receipt .= "\n\n\n\n";
        $receipt .= chr(27) . chr(100) . chr(1); // ESC d 1 - Partial cut

        return $receipt;
    }

    private function getTestReceipt(): string
    {
        $receipt = "";

        // Initialize printer
        $receipt .= chr(27) . chr(64); // ESC @ - Initialize printer

        // Enable Traditional Chinese Big5 encoding
        $receipt .= chr(27) . chr(36); // ESC $ - Enable Kanji/Chinese character mode

        $receipt .= "Invoice Ninja Kitchen Printer\n";
        $receipt .= "TCP/IP Direct Printing\n";
        $receipt .= "\n";
        $receipt .= "Printer IP: " . $this->printerIp . "\n";
        $receipt .= "Port: " . $this->printerPort . "\n";
        $receipt .= "Time: " . now()->format('Y-m-d H:i:s') . "\n";
        $receipt .= "\n";
        $receipt .= "If you see this, printing works!\n";

        // Feed and cut (StarPRNT: ESC d)
        $receipt .= "\n\n\n\n";
        $receipt .= chr(27) . chr(100) . chr(1); // ESC d 1 - Partial cut

        return $receipt;
    }

    private function sendToPrinter(string $data): bool
    {
        try {
            $socket = @fsockopen($this->printerIp, $this->printerPort, $errno, $errstr, 5);

            if (!$socket) {
                throw new Exception("Cannot connect to printer: {$errstr} ({$errno})");
            }

            // Send data
            fwrite($socket, $data);
            fflush($socket);

            // Close connection
            fclose($socket);

            Log::info('Successfully sent data to printer', [
                'ip' => $this->printerIp,
                'port' => $this->printerPort,
                'data_length' => strlen($data)
            ]);

            return true;

        } catch (Exception $e) {
            Log::error('Failed to send to printer', [
                'error' => $e->getMessage(),
                'ip' => $this->printerIp,
                'port' => $this->printerPort
            ]);
            throw $e;
        }
    }
}