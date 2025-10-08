<?php

namespace Modules\KitchenPrinter\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CloudPRNTService
{
    protected string $printerIp;
    protected int $printerPort;
    protected bool $enabled;
    protected int $timeout;

    public function __construct()
    {
        $this->printerIp = config('kitchenprinter.tcp.ip', '');
        $this->printerPort = config('kitchenprinter.tcp.port', 9100);
        $this->enabled = config('kitchenprinter.enabled', false);
        $this->timeout = config('kitchenprinter.tcp.timeout', 5);
    }

    public function print(string $starMarkup): array
    {
        if (!$this->enabled) {
            throw new \Exception('Kitchen printer is disabled. Set KITCHEN_PRINTER_ENABLED=true in .env');
        }

        if (empty($this->printerIp)) {
            throw new \Exception('Printer IP not configured. Set KITCHEN_PRINTER_IP in .env');
        }

        $jobId = 'job_' . Str::random(16);

        try {
            // Open TCP socket to printer on port 9100
            $socket = @fsockopen($this->printerIp, $this->printerPort, $errno, $errstr, $this->timeout);

            if (!$socket) {
                throw new \Exception("Failed to connect to printer at {$this->printerIp}:{$this->printerPort} - $errstr ($errno)");
            }

            // Convert Star Document Markup to ESC/POS commands
            $escposData = $this->convertToEscPos($starMarkup);

            // Send ESC/POS commands to printer
            fwrite($socket, $escposData);
            fflush($socket);
            fclose($socket);

            Log::info('Kitchen print job sent via TCP/IP', [
                'job_id' => $jobId,
                'printer_ip' => $this->printerIp,
                'printer_port' => $this->printerPort,
            ]);

            return [
                'success' => true,
                'job_id' => $jobId,
            ];

        } catch (\Exception $e) {
            Log::error('Kitchen print failed', [
                'error' => $e->getMessage(),
                'printer_ip' => $this->printerIp,
                'printer_port' => $this->printerPort,
            ]);

            throw $e;
        }
    }

    /**
     * Convert Star Document Markup to ESC/POS commands
     */
    protected function convertToEscPos(string $starMarkup): string
    {
        // ESC/POS command constants
        $ESC = chr(27);
        $GS = chr(29);
        $LF = chr(10);

        // Initialize printer
        $output = $ESC . "@";  // Reset printer

        // Process Star markup line by line
        $lines = explode("\n", $starMarkup);
        $currentMagnify = ['width' => 1, 'height' => 1];
        $currentAlign = 'left';
        $boldOn = false;

        foreach ($lines as $line) {
            // Check for Star markup commands
            if (preg_match('/\[magnify:\s*width\s+(\d+);\s*height\s+(\d+)\]/', $line, $matches)) {
                $width = intval($matches[1]);
                $height = intval($matches[2]);

                // ESC ! n - Select print mode
                $size = 0;
                if ($width == 2) $size |= 0x20;  // Double width
                if ($height == 2) $size |= 0x10; // Double height
                $output .= $ESC . "!" . chr($size);

                $currentMagnify = ['width' => $width, 'height' => $height];
                continue;
            }

            if (preg_match('/\[align:\s*(\w+)\]/', $line, $matches)) {
                $align = strtolower($matches[1]);
                // ESC a n - Justification
                switch ($align) {
                    case 'center':
                        $output .= $ESC . "a" . chr(1);
                        break;
                    case 'right':
                        $output .= $ESC . "a" . chr(2);
                        break;
                    default: // left
                        $output .= $ESC . "a" . chr(0);
                        break;
                }
                $currentAlign = $align;
                continue;
            }

            if (preg_match('/\[bold:\s*on\]/', $line)) {
                $output .= $ESC . "E" . chr(1);  // Bold on
                $boldOn = true;
                continue;
            }

            if (preg_match('/\[bold:\s*off\]/', $line)) {
                $output .= $ESC . "E" . chr(0);  // Bold off
                $boldOn = false;
                continue;
            }

            if (preg_match('/\[cut:\s*feed;\s*partial\]/', $line)) {
                // Feed and partial cut
                $output .= $GS . "V" . chr(66) . chr(3);  // Feed and partial cut
                continue;
            }

            // Regular text line
            if (trim($line) !== '') {
                $output .= $line . $LF;
            } else {
                $output .= $LF;
            }
        }

        return $output;
    }

    public function testConnection(): bool
    {
        if (!$this->enabled || empty($this->printerIp)) {
            return false;
        }

        try {
            // Try to open socket to printer
            $socket = @fsockopen($this->printerIp, $this->printerPort, $errno, $errstr, 2);

            if ($socket) {
                fclose($socket);
                return true;
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }
}
