<?php

namespace App\Services;

use App\Models\Invoice;
use Exception;
use Illuminate\Support\Facades\Log;

class KitchenPrinterService
{
    private $printerIp = '10.0.0.150';
    private $printerPort = 9100;

    public function testConnection()
    {
        try {
            $socket = @fsockopen($this->printerIp, $this->printerPort, $errno, $errstr, 5);
            if ($socket) {
                fclose($socket);
                return ['success' => true, 'message' => 'Printer connected successfully'];
            }
            return ['success' => false, 'message' => "Connection failed: $errstr ($errno)"];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function printTestReceipt()
    {
        $testData = $this->formatTestReceipt();
        return $this->sendToPrinter($testData);
    }

    public function printInvoice(Invoice $invoice)
    {
        $printData = $this->formatInvoiceForKitchen($invoice);
        return $this->sendToPrinter($printData);
    }

    private function formatTestReceipt()
    {
        $esc = chr(27);
        $gs = chr(29);

        $output = "";
        $output .= $esc . "@"; // Initialize printer
        $output .= $esc . "R" . chr(0); // Set character code table to USA

        // Center alignment
        $output .= $esc . "a" . chr(1);

        // Double size text
        $output .= $gs . "!" . chr(0x11);
        $output .= "** TEST PRINT **\n";
        $output .= $gs . "!" . chr(0x00); // Normal size

        $output .= "\n";
        $output .= "Printer Connection: OK\n";
        $output .= "Time: " . date('Y-m-d H:i:s') . "\n";
        $output .= "UTF-8 Test: 中文測試\n";
        $output .= "\n";

        // Left alignment
        $output .= $esc . "a" . chr(0);
        $output .= "--------------------------------\n";
        $output .= "Item 1                    x2\n";
        $output .= "Item 2                    x1\n";
        $output .= "--------------------------------\n";

        // Cut paper
        $output .= $gs . "V" . chr(66) . chr(0);

        return $output;
    }

    private function formatInvoiceForKitchen(Invoice $invoice)
    {
        $esc = chr(27);
        $gs = chr(29);

        $output = "";
        $output .= $esc . "@"; // Initialize printer
        $output .= $esc . "R" . chr(0); // Set character code table

        // Header - centered and double size
        $output .= $esc . "a" . chr(1); // Center alignment
        $output .= $gs . "!" . chr(0x11); // Double width and height
        $output .= "** 廚房單 **\n";
        $output .= $gs . "!" . chr(0x00); // Normal size

        // Order info
        $output .= "\n";
        $output .= "單號: " . $invoice->number . "\n";
        $output .= "時間: " . $invoice->created_at->format('Y-m-d H:i:s') . "\n";

        // Table number if available
        if ($invoice->po_number) {
            $output .= $gs . "!" . chr(0x10); // Double width
            $output .= "桌號: " . $invoice->po_number . "\n";
            $output .= $gs . "!" . chr(0x00); // Normal size
        }

        // Customer name
        if ($invoice->client) {
            $output .= "客戶: " . $invoice->client->name . "\n";
        }

        $output .= "\n";

        // Items - left aligned
        $output .= $esc . "a" . chr(0); // Left alignment
        $output .= "================================\n";

        foreach ($invoice->line_items as $item) {
            // Item name and quantity in larger font
            $output .= $gs . "!" . chr(0x10); // Double width

            // Format: Quantity x Item Name
            $qty = number_format($item->quantity, 0);
            $output .= $qty . " x " . $item->product_key . "\n";

            // Notes in normal size if present
            if (!empty($item->notes)) {
                $output .= $gs . "!" . chr(0x00); // Normal size
                $output .= "   備註: " . $item->notes . "\n";
                $output .= $gs . "!" . chr(0x10); // Back to double width
            }

            $output .= $gs . "!" . chr(0x00); // Normal size
            $output .= "--------------------------------\n";
        }

        // Footer
        $output .= "\n";
        $output .= $esc . "a" . chr(1); // Center alignment

        if ($invoice->public_notes) {
            $output .= "備註: " . $invoice->public_notes . "\n";
            $output .= "\n";
        }

        $output .= "** 單據結束 **\n";
        $output .= "\n\n\n";

        // Cut paper
        $output .= $gs . "V" . chr(66) . chr(0);

        return $output;
    }

    private function sendToPrinter($data)
    {
        try {
            $socket = @fsockopen($this->printerIp, $this->printerPort, $errno, $errstr, 5);

            if (!$socket) {
                throw new Exception("Cannot connect to printer: $errstr ($errno)");
            }

            // Convert to UTF-8 if needed
            $data = mb_convert_encoding($data, 'UTF-8', 'auto');

            fwrite($socket, $data);
            fclose($socket);

            Log::info('Kitchen print job sent successfully');
            return ['success' => true, 'message' => 'Print job sent successfully'];

        } catch (Exception $e) {
            Log::error('Kitchen printer error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}