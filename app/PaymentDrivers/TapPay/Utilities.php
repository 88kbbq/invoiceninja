<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2025. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\PaymentDrivers\TapPay;

/**
 * Trait Utilities
 *
 * Helper methods for TapPay payment driver
 */
trait Utilities
{
    /**
     * Get payment description for TapPay API
     *
     * @return string
     */
    public function getTapPayDescription(): string
    {
        $invoice_numbers = collect($this->payment_hash->invoices())
            ->pluck('invoice_number')
            ->implode(', ');

        return "Invoice Payment: {$invoice_numbers}";
    }

    /**
     * Get publishable key (App Key for frontend)
     *
     * @return string
     */
    public function getPublishableKey(): string
    {
        return $this->company_gateway->getConfigField('appKey');
    }

    /**
     * Get App ID for frontend SDK initialization
     *
     * @return string
     */
    public function getAppId(): string
    {
        return $this->company_gateway->getConfigField('appId');
    }

    /**
     * Get server type (sandbox or production)
     *
     * @return string
     */
    public function getServerType(): string
    {
        return $this->company_gateway->getConfigField('testMode') ? 'sandbox' : 'production';
    }

    /**
     * Convert amount to TapPay format (integer, no decimal places)
     * TapPay requires amounts in smallest currency unit (cents, yen, etc.)
     *
     * @param float $amount
     * @return int
     */
    public function convertToTapPayAmount(float $amount): int
    {
        // For most currencies, multiply by 100 to get cents
        // JPY and similar zero-decimal currencies should not be multiplied
        $currency = $this->client->getCurrencyCode();

        $zeroDecimalCurrencies = ['JPY', 'KRW', 'TWD', 'VND'];

        if (in_array($currency, $zeroDecimalCurrencies)) {
            return (int) round($amount);
        }

        return (int) round($amount * 100);
    }
}
