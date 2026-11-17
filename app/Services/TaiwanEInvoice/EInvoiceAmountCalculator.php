<?php

namespace App\Services\TaiwanEInvoice;

use InvalidArgumentException;

/**
 * Builds the Amego F0401 amounts for an Invoice Ninja invoice.
 *
 * The one invariant: the e-invoice TotalAmount is the Invoice Ninja invoice
 * total — the same figure the customer sees and TapPay charges
 * (TapPay rounds the invoice amount to whole NT$; so do we). Line items are
 * derived from the invoice, then reconciled so they sum to that total
 * exactly. Line items never define the total.
 *
 * Amego rules (verified against the Amego sandbox, 2026-11-17):
 *  - Every ProductItem: Quantity × UnitPrice == Amount exactly (error 3040172);
 *    Amount integer, UnitPrice ≤ 4 decimals.
 *  - Negative Amount lines (discount) and zero-Amount lines are accepted.
 *  - B2B: SalesAmount must equal round(TotalAmount / 1.05) exactly (3040174);
 *    TaxAmount = TotalAmount − SalesAmount.
 *  - B2C: SalesAmount = TotalAmount, TaxAmount = 0 (prices tax-inclusive).
 *
 * Pure: no framework, DB or HTTP dependencies, so it is unit-testable.
 */
final class EInvoiceAmountCalculator
{
    /** Taiwan business tax (營業稅) rate. Amego TaxType 1 is always 5%. */
    public const VAT_MULTIPLIER = 1.05;

    public const DISCOUNT_LABEL = '折扣';
    public const ROUNDING_LABEL = '尾差調整';
    public const OTHER_LABEL = '其他調整';

    /**
     * @param array<int, array{description: string, quantity: float, gross: float}> $lines
     *        gross = line total after line discount, including line-level taxes
     *        (Invoice Ninja gross_line_total), before invoice-level discount/taxes.
     * @param float $invoiceTotal         Invoice Ninja invoice amount.
     * @param float $invoiceTaxMultiplier 1 + Σ invoice-level EXCLUSIVE tax rates / 100
     *                                    (1.0 when taxes are inclusive or absent).
     * @param bool  $hasInvoiceDiscount   Invoice-level discount is set.
     * @param bool  $isB2B                Buyer has an 8-digit 統一編號.
     * @return array{items: array<int, array{description: string, quantity: string, unitPrice: string, amount: int}>, salesAmount: int, taxAmount: int, totalAmount: int}
     */
    public static function calculate(
        array $lines,
        float $invoiceTotal,
        float $invoiceTaxMultiplier,
        bool $hasInvoiceDiscount,
        bool $isB2B
    ): array {
        $total = self::wholeDollars($invoiceTotal);
        if ($total <= 0) {
            throw new InvalidArgumentException("Invoice total must be positive to issue an e-invoice (got {$invoiceTotal})");
        }

        $items = [];
        foreach ($lines as $line) {
            $quantity = (float) $line['quantity'];
            if (abs($quantity) < 0.0000005) {
                continue; // Quantity 0 contributes nothing on the invoice either
            }
            $raw = (float) $line['gross'] * $invoiceTaxMultiplier;
            [$unitPrice, $amount] = self::exactLine($quantity, $raw);
            $items[] = [
                'description' => $line['description'],
                'quantity' => self::formatDecimal($quantity, 6),
                'unitPrice' => $unitPrice,
                'amount' => $amount,
            ];
        }

        $residual = $total - array_sum(array_column($items, 'amount'));

        if ($residual !== 0) {
            if ($hasInvoiceDiscount && $residual < 0) {
                // Invoice discount (tax-inclusive) plus any rounding, as one line.
                $items[] = self::singleLine(self::DISCOUNT_LABEL, $residual);
            } elseif (abs($residual) <= max(1, count($items))) {
                // Per-line rounding drift: fold into a line where it stays exact,
                // else show it explicitly.
                if (!self::absorb($items, $residual)) {
                    $items[] = self::singleLine(self::ROUNDING_LABEL, $residual);
                }
            } else {
                // Anything else Invoice Ninja put in the total (surcharges, fees).
                $items[] = self::singleLine(self::OTHER_LABEL, $residual);
            }
        }

        if ($isB2B) {
            $sales = (int) round($total / self::VAT_MULTIPLIER);
            $tax = $total - $sales;
        } else {
            $sales = $total;
            $tax = 0;
        }

        return [
            'items' => $items,
            'salesAmount' => $sales,
            'taxAmount' => $tax,
            'totalAmount' => $total,
        ];
    }

    /**
     * Whole NT$, rounded the same way TapPay's convertToTapPayAmount rounds.
     */
    public static function wholeDollars(float $amount): int
    {
        return (int) round($amount);
    }

    /**
     * Nearest integer Amount to $raw for which UnitPrice = Amount / Quantity
     * has ≤ 4 decimals exactly. Ties go to the smaller Amount.
     *
     * @return array{0: string, 1: int} [unitPrice, amount]
     */
    public static function exactLine(float $quantity, float $raw): array
    {
        $center = (int) round($raw);
        for ($step = 0; $step <= 50; $step++) {
            foreach ($step === 0 ? [$center] : [$center - $step, $center + $step] as $candidate) {
                $unitPrice = self::exactUnitPrice($quantity, $candidate);
                if ($unitPrice !== null) {
                    return [$unitPrice, $candidate];
                }
            }
        }

        // No exactly representable Amount nearby (pathological quantity).
        // Amego would reject any non-exact pair, so fail loudly instead.
        throw new InvalidArgumentException("No exact UnitPrice for quantity {$quantity} near amount {$raw}");
    }

    /**
     * UnitPrice string if Amount / Quantity has ≤ 4 decimals exactly, else null.
     * Integer arithmetic: quantity is scaled to 6 decimals (Invoice Ninja's max).
     */
    private static function exactUnitPrice(float $quantity, int $amount): ?string
    {
        $qtyScaled = (int) round($quantity * 1_000_000);
        if ($qtyScaled === 0) {
            return null;
        }
        // UnitPrice × 10^4 = amount × 10^10 / qtyScaled must be an integer.
        $numerator = $amount * 10_000_000_000;
        if ($numerator % $qtyScaled !== 0) {
            return null;
        }
        $upTimes10k = intdiv($numerator, $qtyScaled);

        return self::formatDecimal($upTimes10k / 10_000, 4);
    }

    /**
     * Add $residual to the largest line that stays exact and keeps its sign.
     *
     * @param array<int, array{description: string, quantity: string, unitPrice: string, amount: int}> $items
     */
    private static function absorb(array &$items, int $residual): bool
    {
        $order = array_keys($items);
        usort($order, fn ($a, $b) => $items[$b]['amount'] <=> $items[$a]['amount']);

        foreach ($order as $i) {
            $newAmount = $items[$i]['amount'] + $residual;
            if ($items[$i]['amount'] <= 0 || $newAmount <= 0) {
                continue;
            }
            $unitPrice = self::exactUnitPrice((float) $items[$i]['quantity'], $newAmount);
            if ($unitPrice !== null) {
                $items[$i]['amount'] = $newAmount;
                $items[$i]['unitPrice'] = $unitPrice;

                return true;
            }
        }

        return false;
    }

    /**
     * @return array{description: string, quantity: string, unitPrice: string, amount: int}
     */
    private static function singleLine(string $description, int $amount): array
    {
        return [
            'description' => $description,
            'quantity' => '1',
            'unitPrice' => (string) $amount,
            'amount' => $amount,
        ];
    }

    private static function formatDecimal(float $value, int $decimals): string
    {
        $s = rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');

        return ($s === '' || $s === '-0') ? '0' : $s;
    }
}
