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

            // Send Star Document Markup directly to printer
            fwrite($socket, $starMarkup);
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
