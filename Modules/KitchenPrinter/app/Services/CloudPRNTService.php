<?php

namespace Modules\KitchenPrinter\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

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

        if (empty($this->printerUrl)) {
            throw new \Exception('Printer URL not configured. Set CLOUDPRNT_URL in .env');
        }

        try {
            // CloudPRNT Protocol: POST print job
            $response = $this->httpClient->post($this->printerUrl, [
                'json' => [
                    'jobReady' => true,
                    'mediaTypes' => ['application/vnd.star.markup'],
                ],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
            ]);

            $jobId = $response->getHeaderLine('X-Star-Job-Id');

            // Upload print data
            $uploadResponse = $this->httpClient->post($this->printerUrl . '/'. $jobId, [
                'body' => $starMarkup,
                'headers' => [
                    'Content-Type' => 'application/vnd.star.markup',
                ],
            ]);

            Log::info('Kitchen print job sent', [
                'job_id' => $jobId,
                'printer_mac' => $this->printerMac,
            ]);

            return [
                'success' => true,
                'job_id' => $jobId,
            ];

        } catch (\Exception $e) {
            Log::error('Kitchen print failed', [
                'error' => $e->getMessage(),
                'printer_url' => $this->printerUrl,
            ]);

            throw $e;
        }
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
