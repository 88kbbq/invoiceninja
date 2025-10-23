<?php

namespace App\Services\KitchenPrinter\Transport;

use Exception;
use Illuminate\Support\Facades\Log;

class WebPrntTransport
{
    /**
     * Send a WebPRNT payload to the configured endpoint.
     */
    public function send(string $payload, array $config, array $overrides = []): array
    {
        [$url, $host, $port, $options] = $this->buildRequestOptions($payload, $config, $overrides);

        $ch = curl_init($url);
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new Exception("WebPRNT request failed: {$curlError}");
        }

        if ($httpCode !== 200) {
            throw new Exception("WebPRNT endpoint returned HTTP {$httpCode}");
        }

        $success = $this->isSuccessfulResponse($response);

        if (!$success) {
            throw new Exception('WebPRNT response did not indicate success');
        }

        Log::info('Kitchen printer WebPRNT transport succeeded', [
            'url' => $url,
            'host' => $host,
            'port' => $port,
            'status' => $httpCode,
        ]);

        return [
            'success' => true,
            'transport' => 'webprnt',
            'status' => $httpCode,
            'host' => $host,
            'port' => $port,
            'url' => $url,
            'response' => $response,
        ];
    }

    /**
     * Attempt a light-weight connectivity test (HEAD request).
     */
    public function testConnection(array $config, array $overrides = []): bool
    {
        [$url, $host, $port, $options] = $this->buildRequestOptions('', $config, $overrides, true);

        $ch = curl_init($url);
        curl_setopt_array($ch, $options + [
            CURLOPT_NOBODY => true,
            CURLOPT_HTTPGET => false,
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            Log::warning('Kitchen printer WebPRNT connectivity error', [
                'url' => $url,
                'error' => $curlError,
            ]);
            return false;
        }

        return $httpCode >= 200 && $httpCode < 400;
    }

    /**
     * Prepare CURL options and resolved endpoint data.
     */
    protected function buildRequestOptions(string $payload, array $config, array $overrides = [], bool $isProbe = false): array
    {
        $scheme = strtolower($overrides['scheme'] ?? $config['scheme'] ?? 'http');
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new Exception("Invalid WebPRNT scheme: {$scheme}");
        }

        $host = $overrides['host'] ?? $overrides['ip'] ?? $config['host'] ?? $config['ip'] ?? null;
        if (empty($host)) {
            throw new Exception('WebPRNT host is not configured');
        }

        $port = $overrides['port'] ?? $config['port'] ?? null;
        $port = $port !== null ? (int) $port : null;

        $path = $overrides['path'] ?? $config['path'] ?? '/StarWebPRNT/SendMessage';
        if ($path && $path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }

        $timeout = (int) ($overrides['timeout'] ?? $config['timeout'] ?? 10);
        $verifySsl = (bool) ($overrides['verify_ssl'] ?? $config['verify_ssl'] ?? ($scheme === 'https'));

        $defaultPort = $scheme === 'https' ? 443 : 80;
        $portPart = $port && $port !== $defaultPort ? ':' . $port : ($port ? ':' . $port : '');
        $url = sprintf('%s://%s%s%s', $scheme, $host, $portPart, $path);

        $options = [
            CURLOPT_POST => !$isProbe,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/xml; charset=UTF-8',
            ],
        ];

        if (!$isProbe) {
            $options[CURLOPT_POSTFIELDS] = $payload;
        }

        if ($scheme === 'https') {
            $options[CURLOPT_SSL_VERIFYPEER] = $verifySsl;
            $options[CURLOPT_SSL_VERIFYHOST] = $verifySsl ? 2 : 0;
        }

        $username = $overrides['username'] ?? $config['username'] ?? null;
        $password = $overrides['password'] ?? $config['password'] ?? null;

        if (!empty($username) && !empty($password)) {
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $options[CURLOPT_USERPWD] = $username . ':' . $password;
        }

        return [$url, $host, $port ?? $defaultPort, $options];
    }

    /**
     * Determine whether the printer response indicates success.
     */
    protected function isSuccessfulResponse(?string $response): bool
    {
        if ($response === null || $response === '') {
            return false;
        }

        $trimmed = trim($response);

        if (stripos($trimmed, 'success') !== false) {
            return true;
        }

        if (stripos($trimmed, 'true') !== false) {
            return true;
        }

        $xml = @simplexml_load_string($trimmed);
        if ($xml && isset($xml->Response)) {
            $value = strtolower((string) $xml->Response);
            return in_array($value, ['true', 'success'], true);
        }

        return false;
    }
}
