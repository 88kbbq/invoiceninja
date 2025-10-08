<?php

namespace Modules\KitchenPrinter\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CloudPRNTService
{
    protected Client $httpClient;
    protected string $printerUrl;
    protected string $printerMac;
    protected bool $enabled;

    public function __construct()
    {
        $this->httpClient = new Client(['timeout' => 10]);
        $this->printerUrl = config('kitchenprinter.cloudprnt.url', '');
        $this->printerMac = config('kitchenprinter.cloudprnt.mac_address', '');
        $this->enabled = config('kitchenprinter.enabled', false);
    }

    public function print(string $starMarkup): array
    {
        if (!$this->enabled) {
            throw new \Exception('Kitchen printer is disabled. Set KITCHEN_PRINTER_ENABLED=true in .env');
        }

        if (empty($this->printerMac)) {
            throw new \Exception('Printer MAC address not configured. Set CLOUDPRNT_MAC in .env');
        }

        // Generate unique job ID
        $jobId = 'job_' . Str::random(16);

        // Store print job in cache for printer to poll
        // Jobs expire after 5 minutes if not picked up
        Cache::put("cloudprnt_job_{$this->printerMac}", $starMarkup, now()->addMinutes(5));

        Log::info('Kitchen print job queued', [
            'job_id' => $jobId,
            'printer_mac' => $this->printerMac,
            'markup_length' => strlen($starMarkup),
        ]);

        return [
            'success' => true,
            'job_id' => $jobId,
        ];
    }

    public function testConnection(): bool
    {
        try {
            $response = $this->httpClient->get($this->printerUrl);
            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            return false;
        }
    }
}
