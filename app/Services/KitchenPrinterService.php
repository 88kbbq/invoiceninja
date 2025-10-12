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
        $this->printerIp = config('kitchenprinter.tcp.ip', '10.0.0.150');
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

        // Start building receipt with StarPRNT commands
        $receipt = "";

        // Initialize printer
        $receipt .= chr(27) . chr(64); // ESC @ - Initialize printer

        // Set alignment to center (StarPRNT: ESC GS a)
        $receipt .= chr(27) . chr(29) . chr(97) . chr(1); // ESC GS a 1 - Center alignment

        // Double width and height for header (StarPRNT: ESC i)
        $receipt .= chr(27) . chr(105) . chr(1) . chr(1); // ESC i 1 1 - Double width + height
        $receipt .= "*** KITCHEN ORDER ***\n";
        $receipt .= chr(27) . chr(105) . chr(0) . chr(0); // ESC i 0 0 - Normal size

        // Set alignment to left (StarPRNT: ESC GS a)
        $receipt .= chr(27) . chr(29) . chr(97) . chr(0); // ESC GS a 0 - Left alignment
        $receipt .= "\n";

        // Invoice details
        $receipt .= "Order #: " . $invoice->number . "\n";
        $receipt .= "Date: " . Carbon::parse($invoice->date)->format('Y-m-d H:i') . "\n";

        // Check for custom event time/date fields
        if ($invoice->custom_value1) {
            $receipt .= "Event Time: " . $invoice->custom_value1 . "\n";
        }
        if ($invoice->custom_value2) {
            $receipt .= "Event Date: " . $invoice->custom_value2 . "\n";
        }

        $receipt .= "\n";

        // Client info
        $receipt .= chr(27) . chr(69); // ESC E - Emphasized on
        $receipt .= "Customer: " . $client->present()->name() . "\n";
        $receipt .= chr(27) . chr(70); // ESC F - Emphasized off

        if ($client->phone) {
            $receipt .= "Phone: " . $client->phone . "\n";
        }

        $receipt .= str_repeat("-", 40) . "\n";

        // Line items (no prices for kitchen)
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

        // Public notes (if any)
        if ($invoice->public_notes) {
            $receipt .= "\nNotes:\n";
            $receipt .= wordwrap($invoice->public_notes, 40) . "\n";
            $receipt .= str_repeat("-", 40) . "\n";
        }

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

    private function formatQuoteReceipt(Quote $quote): string
    {
        $client = $quote->client;
        $lineItems = $quote->line_items;

        // Start building receipt with StarPRNT commands
        $receipt = "";

        // Initialize printer
        $receipt .= chr(27) . chr(64); // ESC @ - Initialize printer

        // Set alignment to center (StarPRNT: ESC GS a)
        $receipt .= chr(27) . chr(29) . chr(97) . chr(1); // ESC GS a 1 - Center alignment

        // Double width and height for header (StarPRNT: ESC i)
        $receipt .= chr(27) . chr(105) . chr(1) . chr(1); // ESC i 1 1 - Double width + height
        $receipt .= "*** QUOTE ***\n";
        $receipt .= chr(27) . chr(105) . chr(0) . chr(0); // ESC i 0 0 - Normal size

        // Set alignment to left (StarPRNT: ESC GS a)
        $receipt .= chr(27) . chr(29) . chr(97) . chr(0); // ESC GS a 0 - Left alignment
        $receipt .= "\n";

        // Quote details
        $receipt .= "Quote #: " . $quote->number . "\n";
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
        $receipt .= chr(27) . chr(33) . chr(8); // ESC ! 8 - Emphasized
        $receipt .= "ITEMS:\n";
        $receipt .= chr(27) . chr(33) . chr(0); // ESC ! 0 - Normal
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

        // Set alignment to center (StarPRNT: ESC GS a)
        $receipt .= chr(27) . chr(29) . chr(97) . chr(1); // ESC GS a 1 - Center alignment

        // Double width and height for header (StarPRNT: ESC i)
        $receipt .= chr(27) . chr(105) . chr(1) . chr(1); // ESC i 1 1 - Double width + height
        $receipt .= "*** TEST PRINT ***\n";
        $receipt .= chr(27) . chr(105) . chr(0) . chr(0); // ESC i 0 0 - Normal size

        // Set alignment to left (StarPRNT: ESC GS a)
        $receipt .= chr(27) . chr(29) . chr(97) . chr(0); // ESC GS a 0 - Left alignment
        $receipt .= "\n";

        $receipt .= "Invoice Ninja Kitchen Printer\n";
        $receipt .= "TCP/IP Direct Printing\n";
        $receipt .= "\n";
        $receipt .= "Printer IP: " . $this->printerIp . "\n";
        $receipt .= "Port: " . $this->printerPort . "\n";
        $receipt .= "Time: " . now()->format('Y-m-d H:i:s') . "\n";
        $receipt .= "\n";
        $receipt .= "If you see this, printing works!\n";

        // Feed and cut
        $receipt .= "\n\n\n\n";
        $receipt .= chr(29) . chr(86) . chr(66) . chr(0); // GS V B 0 - Partial cut

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