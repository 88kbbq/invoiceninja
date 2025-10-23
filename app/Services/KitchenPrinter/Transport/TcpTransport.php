<?php

namespace App\Services\KitchenPrinter\Transport;

use Exception;
use Illuminate\Support\Facades\Log;

class TcpTransport
{
    /**
     * Send a payload to the printer using raw TCP/IP.
     */
    public function send(string $payload, array $config, array $overrides = []): array
    {
        [$host, $port, $timeout] = $this->resolveEndpoint($config, $overrides);

        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

        if (!$socket) {
            throw new Exception("Cannot connect to printer: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, $timeout);

        $bytes = fwrite($socket, $payload);
        fflush($socket);

        // Give the printer a moment to process the buffer before closing.
        usleep(500000); // 500ms

        fclose($socket);

        Log::info('Kitchen printer TCP transport succeeded', [
            'host' => $host,
            'port' => $port,
            'bytes_written' => $bytes,
        ]);

        return [
            'success' => true,
            'transport' => 'tcp',
            'host' => $host,
            'port' => $port,
            'bytes_written' => $bytes,
        ];
    }

    /**
     * Lightweight reachability test.
     */
    public function testConnection(array $config, array $overrides = []): bool
    {
        [$host, $port, $timeout] = $this->resolveEndpoint($config, $overrides);

        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($socket) {
            fclose($socket);
            return true;
        }

        Log::warning('Kitchen printer TCP connection test failed', [
            'host' => $host,
            'port' => $port,
            'error' => $errstr,
            'errno' => $errno,
        ]);

        return false;
    }

    /**
     * Resolve endpoint details from config + overrides.
     */
    protected function resolveEndpoint(array $config, array $overrides = []): array
    {
        $host = $overrides['host'] ?? $overrides['ip'] ?? $config['host'] ?? $config['ip'] ?? null;
        $port = $overrides['port'] ?? $config['port'] ?? 9100;
        $timeout = $overrides['timeout'] ?? $config['timeout'] ?? 5;

        if (empty($host)) {
            throw new Exception('Printer host is not configured for TCP transport');
        }

        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            throw new Exception("Invalid TCP printer IP: {$host}");
        }

        $port = (int) $port;
        if ($port <= 0 || $port > 65535) {
            throw new Exception("Invalid TCP printer port: {$port}");
        }

        return [$host, $port, (int) $timeout];
    }
}
