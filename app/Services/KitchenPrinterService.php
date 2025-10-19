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
    public function printInvoiceWebPRNT(Invoice $invoice, array $overrides = []): array
    {
        return $this->printInvoiceWebPRNTDirect($invoice, $overrides);
    }

    /**
     * Print invoice using WebPRNT directly to printer
     * Sends XML formatted data to printer's WebPRNT endpoint
     */
    public function printInvoiceWebPRNTDirect(Invoice $invoice, array $overrides = []): array
    {
        if (!$this->enabled) {
            throw new Exception('Kitchen printer is disabled');
        }

        try {
            // Build WebPRNT XML content
            $xml = $this->formatInvoiceWebPRNT($invoice);

            $webprnt = config('kitchenprinter.webprnt', []);
            $scheme = $overrides['scheme'] ?? $webprnt['scheme'] ?? 'https';
            $host = $overrides['ip'] ?? $webprnt['ip'] ?? $this->printerIp;
            $port = array_key_exists('port', $overrides)
                ? (int) $overrides['port']
                : ($webprnt['port'] ?? 443);
            $path = $overrides['path'] ?? $webprnt['path'] ?? '/StarWebPRNT/SendMessage';
            if ($path && $path[0] !== '/') {
                $path = '/' . ltrim($path, '/');
            }
            $verifySsl = array_key_exists('verify_ssl', $overrides)
                ? (bool) $overrides['verify_ssl']
                : (bool) ($webprnt['verify_ssl'] ?? false);
            $timeout = array_key_exists('timeout', $overrides)
                ? (int) $overrides['timeout']
                : (int) ($webprnt['timeout'] ?? 10);

            $defaultPort = $scheme === 'https' ? 443 : 80;
            $portPart = ($port && (int) $port !== $defaultPort) ? ':' . $port : '';
            $url = sprintf('%s://%s%s%s', $scheme, $host, $portPart, $path);

            $options = [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: text/xml; charset=UTF-8',
                ],
                CURLOPT_POSTFIELDS => $xml,
                CURLOPT_TIMEOUT => $timeout,
            ];

            if ($scheme === 'https') {
                $options[CURLOPT_SSL_VERIFYPEER] = $verifySsl;
                $options[CURLOPT_SSL_VERIFYHOST] = $verifySsl ? 2 : 0;
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, $options);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                throw new Exception("CURL error: {$curlError}");
            }

            if ($httpCode !== 200) {
                throw new Exception("WebPRNT endpoint returned HTTP {$httpCode}: {$response}");
            }

            // Parse XML response
            $xmlResponse = simplexml_load_string($response);
            $success = isset($xmlResponse->Response) && strpos($xmlResponse->Response, 'true') !== false;

            if (!$success) {
                throw new Exception("WebPRNT print failed. Response: {$response}");
            }

            Log::info('WebPRNT direct print successful', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'webprnt_url' => $url,
                'webprnt_scheme' => $scheme,
                'webprnt_ip' => $host,
                'webprnt_port' => $port,
                'webprnt_path' => $path,
                'webprnt_verify_ssl' => $verifySsl,
            ]);

            return [
                'success' => true,
                'message' => 'Kitchen receipt sent successfully',
                'invoice_number' => $invoice->number,
                'webprnt_url' => $url,
                'webprnt_config' => [
                    'scheme' => $scheme,
                    'ip' => $host,
                    'port' => $port,
                    'path' => $path,
                    'verify_ssl' => $verifySsl,
                    'timeout' => $timeout,
                ],
            ];

        } catch (Exception $e) {
            Log::error('WebPRNT direct print failed', [
                'invoice_id' => $invoice->id ?? null,
                'invoice_number' => $invoice->number ?? null,
                'error' => $e->getMessage(),
                'webprnt_ip' => $host ?? null,
                'webprnt_port' => $port ?? null,
                'webprnt_scheme' => $scheme ?? null,
                'webprnt_url' => $url ?? null,
                'webprnt_path' => $path ?? null,
                'webprnt_verify_ssl' => $verifySsl ?? null,
            ]);
            throw $e;
        }
    }

    /**
     * Format invoice data as WebPRNT XML
     * Uses Star WebPRNT XML syntax for formatting
     */
    private function formatInvoiceWebPRNT(Invoice $invoice): string
    {
        $client = $invoice->client;
        $lineItems = $invoice->line_items;

        $commands = [];

        // Initialize printer
        $commands[] = '<initialization/>';

        // Center-aligned, large/bold invoice number
        $commands[] = '<alignment value="center"/>';
        $commands[] = '<text emphasis="true" width="2" height="2">' . htmlspecialchars($invoice->number) . '</text>';
        $commands[] = '<lineFeed/>';

        // Date (large text, centered)
        $commands[] = '<text width="2" height="2">' . htmlspecialchars(Carbon::parse($invoice->date)->format('Y-m-d')) . '</text>';
        $commands[] = '<lineFeed/>';

        // Event/Arrival time (if exists)
        if ($invoice->custom_value1) {
            $commands[] = '<text width="2" height="2">' . htmlspecialchars($invoice->custom_value1 . ' 到達') . '</text>';
            $commands[] = '<lineFeed/>';
        }

        // Black separator line
        $commands[] = '<alignment value="left"/>';
        $commands[] = '<ruledLine thickness="thick"/>';
        $commands[] = '<lineFeed/>';

        // Customer name (bold, large)
        $commands[] = '<text emphasis="true" width="2" height="2">' . htmlspecialchars($client->present()->name()) . '</text>';
        $commands[] = '<lineFeed/>';

        // Customer phone
        if ($client->phone) {
            $commands[] = '<text width="2" height="2">' . htmlspecialchars($client->phone) . '</text>';
            $commands[] = '<lineFeed/>';
        }

        // Separator line
        $commands[] = '<ruledLine thickness="thick"/>';
        $commands[] = '<lineFeed/>';

        // Line items
        foreach ($lineItems as $item) {
            if (empty($item->product_key) && empty($item->notes)) {
                continue;
            }

            $itemName = $item->product_key ?: $item->notes;

            // Format quantity
            $qty = $item->quantity;
            if (floor($qty) == $qty) {
                $qtyStr = number_format($qty, 0);
            } else {
                $qtyStr = rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
            }

            // Item line with quantity in inverse box
            $commands[] = '<text width="2" height="2">' . htmlspecialchars($itemName) . '  </text>';
            $commands[] = '<text emphasis="true" invert="true" width="2" height="2"> ' . htmlspecialchars($qtyStr) . ' </text>';
            $commands[] = '<lineFeed/>';
        }

        // Separator line
        $commands[] = '<ruledLine thickness="thick"/>';
        $commands[] = '<lineFeed/>';

        // Public notes
        if (!empty($invoice->public_notes)) {
            $commands[] = '<text>' . htmlspecialchars($invoice->public_notes) . '</text>';
            $commands[] = '<lineFeed/>';
        }

        // Private notes
        if (!empty($invoice->private_notes)) {
            $commands[] = '<text emphasis="true">' . htmlspecialchars($invoice->private_notes) . '</text>';
            $commands[] = '<lineFeed/>';
        }

        // Feed and cut
        $commands[] = '<lineFeed/>';
        $commands[] = '<lineFeed/>';
        $commands[] = '<lineFeed/>';
        $commands[] = '<cut type="partial"/>';

        // Build final XML with proper Star WebPRNT structure
        $commandsStr = implode("\n      ", $commands);
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<StarWebPRNT xmlns="http://www.star-m.jp">' . "\n";
        $xml .= '  <Request>' . "\n";
        $xml .= '    <Contents>' . "\n";
        $xml .= '      ' . $commandsStr . "\n";
        $xml .= '    </Contents>' . "\n";
        $xml .= '  </Request>' . "\n";
        $xml .= '</StarWebPRNT>';

        return $xml;
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

        // Try multiple print trigger methods:

        // Method 1: Form feed (print buffer)
        $receipt .= chr(12); // FF - Form feed (triggers print on many printers)

        // Method 2: ESC/POS full cut (GS V 0)
        $receipt .= chr(29) . chr(86) . chr(0);

        // Method 3: Partial cut as backup (GS V 1)
        // $receipt .= chr(29) . chr(86) . chr(1);

        return $receipt;
    }

    private function sendToPrinter(string $data, ?string $ipOverride = null, ?int $portOverride = null): bool
    {
        try {
            [$ip, $port] = $this->resolveEndpoint($ipOverride, $portOverride);

            // Log raw data being sent (first 200 bytes for debugging)
            Log::debug('Printer data preview', [
                'ip' => $ip,
                'port' => $port,
                'data_length' => strlen($data),
                'data_preview' => substr($data, 0, 200),
                'hex_preview' => bin2hex(substr($data, 0, 50)),
            ]);

            $socket = @fsockopen($ip, $port, $errno, $errstr, 5);

            if (!$socket) {
                throw new Exception("Cannot connect to printer: {$errstr} ({$errno})");
            }

            // Send data
            $bytesWritten = fwrite($socket, $data);
            fflush($socket);

            // IMPORTANT: Add delay before closing socket
            // Star printers need time to process data
            usleep(500000); // 500ms = 0.5 seconds

            // Close connection
            fclose($socket);

            Log::info('Successfully sent data to printer', [
                'ip' => $ip,
                'port' => $port,
                'data_length' => strlen($data),
                'bytes_written' => $bytesWritten,
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
