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

    public function printInvoice(Invoice $invoice, ?string $ipOverride = null, ?int $portOverride = null): bool
    {
        if (!$this->enabled) {
            throw new Exception('Kitchen printer is disabled');
        }

        $receiptData = $this->formatInvoiceReceipt($invoice);
        return $this->sendToPrinter($receiptData, $ipOverride, $portOverride);
    }

    /**
     * Print invoice using WebPRNT service (TEST)
     * Sends to Node.js service on port 3002
     */
    public function printInvoiceWebPRNT(Invoice $invoice): array
    {
        if (!$this->enabled) {
            throw new Exception('Kitchen printer is disabled');
        }

        try {
            $client = $invoice->client;
            $lineItems = $invoice->line_items;

            // Build JSON payload for WebPRNT service
            $data = [
                'orderId' => $invoice->number,
                'date' => Carbon::parse($invoice->date)->format('Y-m-d'),
                'arriveAt' => $invoice->custom_value1 ? $invoice->custom_value1 . ' 到達' : null,
                'customer' => [
                    'name' => $client->present()->name(),
                    'phone' => $client->phone ?? '',
                ],
                'items' => [],
                'publicNotes' => $invoice->public_notes ?? '',
                'privateNotes' => $invoice->private_notes ?? '',
            ];

            // Format line items
            foreach ($lineItems as $item) {
                if (empty($item->product_key) && empty($item->notes)) {
                    continue;
                }

                $itemName = $item->product_key ?: $item->notes;

                // Split Chinese and English parts
                $parts = explode(' ', $itemName, 2);
                $nameZh = $parts[0] ?? '';
                $nameEn = $parts[1] ?? '';

                $data['items'][] = [
                    'nameZh' => $nameZh,
                    'nameEn' => $nameEn,
                    'qty' => (int) $item->quantity,
                ];
            }

            // Send to WebPRNT service
            $ch = curl_init('http://localhost:3002/api/print/kitchen');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'x-api-key: 8875c71f87a5ebc5c5e38ab6c500cdeaa1e1cea50932c298db65739a933b4649',
                ],
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_TIMEOUT => 10,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode !== 200) {
                throw new Exception("WebPRNT service returned HTTP {$httpCode}: {$response}");
            }

            if ($curlError) {
                throw new Exception("CURL error: {$curlError}");
            }

            $result = json_decode($response, true);

            Log::info('WebPRNT print successful', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'job_id' => $result['jobId'] ?? null,
            ]);

            return $result;

        } catch (Exception $e) {
            Log::error('WebPRNT print failed', [
                'invoice_id' => $invoice->id ?? null,
                'invoice_number' => $invoice->number ?? null,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function printQuote(Quote $quote, ?string $ipOverride = null, ?int $portOverride = null): bool
    {
        if (!$this->enabled) {
            throw new Exception('Kitchen printer is disabled');
        }

        $receiptData = $this->formatQuoteReceipt($quote);
        return $this->sendToPrinter($receiptData, $ipOverride, $portOverride);
    }

    public function testConnection(?string $ipOverride = null, ?int $portOverride = null): bool
    {
        try {
            [$ip, $port] = $this->resolveEndpoint($ipOverride, $portOverride);

            $socket = @fsockopen($ip, $port, $errno, $errstr, 2);
            if ($socket) {
                fclose($socket);
                return true;
            }
            return false;
        } catch (Exception $e) {
            Log::error('Printer connection test failed', [
                'error' => $e->getMessage(),
                'target_ip' => $ipOverride ?? $this->printerIp,
                'target_port' => $portOverride ?? $this->printerPort,
            ]);
            return false;
        }
    }

    public function testPrint(?string $ipOverride = null, ?int $portOverride = null): bool
    {
        [$ip, $port] = $this->resolveEndpoint($ipOverride, $portOverride);
        $testReceipt = $this->getTestReceipt($ip, $port);

        return $this->sendToPrinter($testReceipt, $ip, $port);
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

        // Invoice number at top
        $receipt .= $toBig5($invoice->number) . "\n";

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

            // Get item name (product_key contains both Chinese and English)
            $itemName = $item->product_key ?: $item->notes;

            // Format quantity - show decimals if not whole number
            $qty = $item->quantity;
            if (floor($qty) == $qty) {
                $qtyStr = number_format($qty, 0); // Whole number: "3"
            } else {
                $qtyStr = rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.'); // Decimals: "2.5" or "1.42"
            }

            // Line width for 2x size text (approx 20 chars per line)
            $lineWidth = 20;
            $minSpacing = 3;
            $qtyWidth = strlen($qtyStr) + 2; // Add padding inside box

            // Calculate available width for item name
            $nameWidth = $lineWidth - $qtyWidth - $minSpacing;

            // Item name (left-aligned, may wrap)
            $receipt .= $toBig5($itemName);

            // Spacing before quantity
            $receipt .= str_repeat(" ", $minSpacing);

            // Inverse video ON (white text on black)
            $receipt .= chr(27) . chr(29) . chr(66) . chr(1); // ESC GS B 1

            // Quantity in box with padding
            $receipt .= " " . $qtyStr . " ";

            // Inverse video OFF
            $receipt .= chr(27) . chr(29) . chr(66) . chr(0); // ESC GS B 0

            $receipt .= "\n";
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

    private function getTestReceipt(string $ip, int $port): string
    {
        $receipt = "";

        // Initialize printer (ESC/POS standard - works on most thermal printers)
        $receipt .= chr(27) . chr(64); // ESC @ - Initialize printer

        // Test content - plain ASCII first
        $receipt .= "================================\n";
        $receipt .= "  KITCHEN PRINTER TEST\n";
        $receipt .= "================================\n";
        $receipt .= "\n";
        $receipt .= "Invoice Ninja Kitchen Printer\n";
        $receipt .= "TCP/IP Direct Printing\n";
        $receipt .= "\n";
        $receipt .= "Printer IP: " . $ip . "\n";
        $receipt .= "Port: " . $port . "\n";
        $receipt .= "Time: " . now()->format('Y-m-d H:i:s') . "\n";
        $receipt .= "\n";
        $receipt .= "If you see this, printing works!\n";
        $receipt .= "\n";
        $receipt .= "================================\n";

        // Feed paper (6 lines)
        $receipt .= "\n\n\n\n\n\n";

        // Paper cut - try multiple methods
        // Method 1: ESC/POS full cut (GS V 0)
        $receipt .= chr(29) . chr(86) . chr(0);

        // Method 2: ESC/POS partial cut (GS V 1) - backup
        // $receipt .= chr(29) . chr(86) . chr(1);

        return $receipt;
    }

    private function sendToPrinter(string $data, ?string $ipOverride = null, ?int $portOverride = null): bool
    {
        try {
            [$ip, $port] = $this->resolveEndpoint($ipOverride, $portOverride);

            $socket = @fsockopen($ip, $port, $errno, $errstr, 5);

            if (!$socket) {
                throw new Exception("Cannot connect to printer: {$errstr} ({$errno})");
            }

            // Send data
            fwrite($socket, $data);
            fflush($socket);

            // Close connection
            fclose($socket);

            Log::info('Successfully sent data to printer', [
                'ip' => $ip,
                'port' => $port,
                'data_length' => strlen($data)
            ]);

            return true;

        } catch (Exception $e) {
            Log::error('Failed to send to printer', [
                'error' => $e->getMessage(),
                'target_ip' => $ipOverride ?? $this->printerIp,
                'target_port' => $portOverride ?? $this->printerPort,
            ]);
            throw $e;
        }
    }

    private function resolveEndpoint(?string $ipOverride, ?int $portOverride): array
    {
        $ip = $this->printerIp;
        $port = $this->printerPort;

        if (!is_null($ipOverride)) {
            if (!filter_var($ipOverride, FILTER_VALIDATE_IP)) {
                throw new Exception("Invalid printer IP override: {$ipOverride}");
            }
            $ip = $ipOverride;
        }

        if (!is_null($portOverride)) {
            if ($portOverride <= 0 || $portOverride > 65535) {
                throw new Exception("Invalid printer port override: {$portOverride}");
            }
            $port = $portOverride;
        }

        return [$ip, $port];
    }
}
