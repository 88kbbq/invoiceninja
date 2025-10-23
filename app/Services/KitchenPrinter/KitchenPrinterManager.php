<?php

namespace App\Services\KitchenPrinter;

use App\Models\Invoice;
use App\Models\Quote;
use App\Services\KitchenPrinter\Formatter\ReceiptFormatter;
use App\Services\KitchenPrinter\Transport\TcpTransport;
use App\Services\KitchenPrinter\Transport\WebPrntTransport;
use Exception;

class KitchenPrinterManager
{
    public function __construct(
        private readonly ReceiptFormatter $formatter,
        private readonly WebPrntTransport $webPrntTransport,
        private readonly TcpTransport $tcpTransport,
    ) {
    }

    /**
     * Determine if the kitchen printer integration is enabled.
     */
    public function isEnabled(): bool
    {
        return (bool) (config('kitchenprinter.enabled') ?? false);
    }

    /**
     * Print an invoice via the configured transport.
     */
    public function printInvoice(Invoice $invoice, array $options = []): array
    {
        $this->ensureEnabled();

        $transport = $this->resolveTransport($options['transport'] ?? null);
        $result = $transport === 'webprnt'
            ? $this->sendWithWebPrnt($this->formatter->formatInvoiceForWebPrnt($invoice), $options)
            : $this->sendWithTcp($this->formatter->formatInvoiceForTcp($invoice), $options);

        $result['invoice_id'] = $invoice->id;
        $result['invoice_number'] = $invoice->number;

        return $result;
    }

    /**
     * Print a quote via the configured transport.
     */
    public function printQuote(Quote $quote, array $options = []): array
    {
        $this->ensureEnabled();

        $transport = $this->resolveTransport($options['transport'] ?? null);
        $result = $transport === 'webprnt'
            ? $this->sendWithWebPrnt($this->formatter->formatQuoteForWebPrnt($quote), $options)
            : $this->sendWithTcp($this->formatter->formatQuoteForTcp($quote), $options);

        $result['quote_id'] = $quote->id;
        $result['quote_number'] = $quote->number;

        return $result;
    }

    /**
     * Lightweight connection probe for the configured transport.
     */
    public function testConnection(array $options = []): bool
    {
        $transport = $this->resolveTransport($options['transport'] ?? null);

        if ($transport === 'webprnt') {
            return $this->webPrntTransport->testConnection($this->getTransportConfig('webprnt'), $options);
        }

        return $this->tcpTransport->testConnection($this->getTransportConfig('tcp'), $options);
    }

    /**
     * Send a canned test ticket through the configured transport.
     */
    public function testPrint(array $options = []): array
    {
        $this->ensureEnabled();

        $transport = $this->resolveTransport($options['transport'] ?? null);

        if ($transport === 'webprnt') {
            $payload = $this->formatter->buildWebPrntTestTicket();
            return $this->sendWithWebPrnt($payload, $options);
        }

        $tcpConfig = $this->getTransportConfig('tcp');
        $host = $options['host'] ?? $options['ip'] ?? $tcpConfig['host'] ?? $tcpConfig['ip'] ?? '127.0.0.1';
        $port = (int) ($options['port'] ?? $tcpConfig['port'] ?? 9100);

        $payload = $this->formatter->buildTcpTestTicket($host, $port);

        return $this->sendWithTcp($payload, $options + ['host' => $host, 'port' => $port]);
    }

    /**
     * Ensure the feature is enabled before performing a print.
     */
    protected function ensureEnabled(): void
    {
        if (!$this->isEnabled()) {
            throw new Exception('Kitchen printer is disabled');
        }
    }

    /**
     * Resolve the transport from config or overrides.
     */
    protected function resolveTransport(?string $transport, bool $fallbackToDefault = true): string
    {
        $transport = $transport ?: ($fallbackToDefault ? config('kitchenprinter.default_transport', 'webprnt') : null);
        $transport = $transport ? strtolower($transport) : null;

        if (!$transport) {
            throw new Exception('Kitchen printer transport not specified');
        }

        if (!in_array($transport, ['webprnt', 'tcp'], true)) {
            throw new Exception("Unsupported kitchen printer transport: {$transport}");
        }

        return $transport;
    }

    /**
     * Dispatch payload via WebPRNT.
     */
    protected function sendWithWebPrnt(string $payload, array $overrides = []): array
    {
        $result = $this->webPrntTransport->send($payload, $this->getTransportConfig('webprnt'), $overrides);
        $result['payload_length'] = strlen($payload);

        return $result;
    }

    /**
     * Dispatch payload via TCP.
     */
    protected function sendWithTcp(string $payload, array $overrides = []): array
    {
        $result = $this->tcpTransport->send($payload, $this->getTransportConfig('tcp'), $overrides);
        $result['payload_length'] = strlen($payload);
        $result['encoding'] = 'big5';

        return $result;
    }

    /**
     * Fetch transport-specific configuration.
     */
    protected function getTransportConfig(string $transport): array
    {
        $config = config("kitchenprinter.{$transport}", []);

        if (!is_array($config)) {
            return [];
        }

        return $config;
    }
}
