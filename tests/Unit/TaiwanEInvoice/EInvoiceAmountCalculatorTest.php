<?php

namespace Tests\Unit\TaiwanEInvoice;

use App\Services\TaiwanEInvoice\EInvoiceAmountCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Fixtures are real production invoices whose e-invoice total did not match
 * the Invoice Ninja total before this fix (audit 2026-11-17).
 *
 * Invariants checked on every case:
 *  - TotalAmount == invoice total rounded to whole NT$ (what TapPay charges)
 *  - Σ ProductItem.Amount == TotalAmount
 *  - every line: Quantity × UnitPrice == Amount exactly, UnitPrice ≤ 4 decimals
 *  - B2B: SalesAmount == round(Total / 1.05), Tax = Total − Sales
 *  - B2C: SalesAmount == Total, Tax == 0
 */
class EInvoiceAmountCalculatorTest extends TestCase
{
    private const LINES_97148110300627 = [
        ['感恩節火雞大餐 Thanksgiving Set', 1, 9800],
        ['手撕火雞 Pulled Turkey', 2, 3920],
        ['玉米綿泥 Cornbread Stuffing (1kg)', 2, 1600],
        ['馬鈴薯泥 Mashed Potatoes(kg)', 3, 2400],
        ['炭烤蔬菜 Roasted Vegetables', 3, 3000],
        ['起司通心麵 Mac\'n Cheese(kg)', 4, 4000],
        ['花椰菜 Broccoli Ranch', 1.4, 1400],
    ];

    private const LINES_97148110200626 = [
        ['牛前胸肉 Beef Brisket(kg)', 4, 7920],
        ['豬肩肉 Pulled Pork (kg)', 4, 5920],
        ['馬鈴薯泥 Mashed Potatoes(kg)', 3, 2400],
        ['涼拌捲心菜 Coleslaw', 3, 2400],
        ['超級沙拉 Super Salad', 3, 3000],
        ['奶油玉米 Creamed Corn(kg)', 3, 2400],
        ['起司通心麵 Mac\'n Cheese(kg)', 3, 3000],
    ];

    private static function lines(array $rows): array
    {
        return array_map(fn ($r) => ['description' => $r[0], 'quantity' => (float) $r[1], 'gross' => (float) $r[2]], $rows);
    }

    private function assertInvariants(array $r, float $invoiceTotal, bool $isB2B): void
    {
        $total = (int) round($invoiceTotal);
        $this->assertSame($total, $r['totalAmount'], 'e-invoice total must equal the invoice total');
        $this->assertSame($total, array_sum(array_column($r['items'], 'amount')), 'line items must sum to the total');

        foreach ($r['items'] as $i => $item) {
            $this->assertMatchesRegularExpression('/^-?\d+(\.\d{1,4})?$/', $item['unitPrice'], "line {$i} UnitPrice format");
            // Exact decimal check: qty × unitPrice == amount, via integer scaling.
            $qty = (int) round((float) $item['quantity'] * 1_000_000);
            $up = (int) round((float) $item['unitPrice'] * 10_000);
            $this->assertSame($item['amount'] * 10_000_000_000, $qty * $up, "line {$i} ({$item['description']}): {$item['quantity']} × {$item['unitPrice']} != {$item['amount']}");
        }

        if ($isB2B) {
            $this->assertSame((int) round($total / 1.05), $r['salesAmount']);
            $this->assertSame($total - $r['salesAmount'], $r['taxAmount']);
        } else {
            $this->assertSame($total, $r['salesAmount']);
            $this->assertSame(0, $r['taxAmount']);
        }
    }

    public function testB2cTaxExclusiveInvoiceIncludesTheTax(): void
    {
        // Paid 27,426 online (26,120 + 5%). Old code sent the pre-tax lines.
        $r = EInvoiceAmountCalculator::calculate(self::lines(self::LINES_97148110300627), 27426, 1.05, false, false);
        $this->assertInvariants($r, 27426, false);
        $this->assertCount(7, $r['items'], 'every line is exact at ×1.05; no adjustment line needed');
        $this->assertSame('10290', $r['items'][0]['unitPrice']);
        $this->assertSame('1050', $r['items'][6]['unitPrice']);
        $this->assertSame('1.4', $r['items'][6]['quantity']);
    }

    public function testB2cInvoiceThatWasSentAs27040(): void
    {
        // Paid 28,392; old e-invoice was 27,040 (tax dropped).
        $r = EInvoiceAmountCalculator::calculate(self::lines(self::LINES_97148110200626), 28392, 1.05, false, false);
        $this->assertInvariants($r, 28392, false);
    }

    public function testB2bTaxExclusiveSplitsSalesAndTaxLikeTheInvoice(): void
    {
        $r = EInvoiceAmountCalculator::calculate(self::lines(self::LINES_97148110300627), 27426, 1.05, false, true);
        $this->assertInvariants($r, 27426, true);
        $this->assertSame(26120, $r['salesAmount'], 'matches the invoice subtotal');
        $this->assertSame(1306, $r['taxAmount'], 'matches the invoice tax');
    }

    public function testInvoiceDiscountBecomesADiscountLine(): void
    {
        // 97148052600580: lines 45,920, invoice discount 920, no tax → 45,000. Old code sent 45,920.
        $rows = [['A', 8, 15840], ['B', 8, 11680], ['C', 5, 7400], ['D', 3, 2400], ['E', 5, 5000], ['F', 2, 1600], ['G', 2, 2000]];
        $r = EInvoiceAmountCalculator::calculate(self::lines($rows), 45000, 1.0, true, true);
        $this->assertInvariants($r, 45000, true);
        $last = end($r['items']);
        $this->assertSame(EInvoiceAmountCalculator::DISCOUNT_LABEL, $last['description']);
        $this->assertSame(-920, $last['amount']);
    }

    public function testDiscountOnTaxExclusiveInvoiceIsTaxInclusive(): void
    {
        // Subtotal 26,120 − 920 discount = 25,200; +5% = 26,460.
        $r = EInvoiceAmountCalculator::calculate(self::lines(self::LINES_97148110300627), 26460, 1.05, true, false);
        $this->assertInvariants($r, 26460, false);
        $this->assertSame(-966, end($r['items'])['amount']);
    }

    public function testZeroPriceLinesStayZero(): void
    {
        // 97148090400608: two 0-price lines were each sent as Amount 1 → 7,995 vs 7,994.
        $rows = [['A', 1.3, 2574], ['B', 1.5, 2220], ['C', 2, 1600], ['D', 2, 1600], ['Free', 2, 0]];
        $r = EInvoiceAmountCalculator::calculate(self::lines($rows), 7994, 1.0, false, true);
        $this->assertInvariants($r, 7994, true);
        $this->assertSame(0, $r['items'][4]['amount']);
    }

    public function testPerLineRoundingDriftIsReconciled(): void
    {
        // 97148091500614: B2B, 5% exclusive, 21,819 invoice; old code sent 21,822.
        $rows = [['A', 2, 3960], ['B', 2, 2960], ['C', 15, 1500], ['D', 2, 2960], ['E', 3, 2400], ['F', 3, 3000], ['G', 3, 3000], ['H', 1, 1000], ['Free', 3, 0]];
        $r = EInvoiceAmountCalculator::calculate(self::lines($rows), 21819, 1.05, false, true);
        $this->assertInvariants($r, 21819, true);
    }

    public function testFractionalInvoiceTotalRoundsLikeTapPay(): void
    {
        // 97148103000521: TWD precision 2 gave 19,057.50.
        $rows = [['A', 1, 4200], ['B', 1.5, 2790], ['C', 2, 3160], ['D', 3, 2400], ['E', 2, 2000], ['F', 2, 2000], ['G', 2, 1600]];
        $r = EInvoiceAmountCalculator::calculate(self::lines($rows), 19057.5, 1.05, false, true);
        $this->assertInvariants($r, 19057.5, true);
        $this->assertSame(19058, $r['totalAmount']);
    }

    public function testDecimalQuantitiesWithFractionalTotal(): void
    {
        // 97148050700578: 61,561.50.
        $rows = [['A', 8, 15840], ['B', 8, 11840], ['C', 2.5, 3950], ['D', 4, 4000], ['E', 4, 3200], ['F', 7.5, 7500], ['G', 5, 4000], ['H', 7.5, 7500], ['I', 1, 800]];
        foreach ([true, false] as $b2b) {
            $r = EInvoiceAmountCalculator::calculate(self::lines($rows), 61561.5, 1.05, false, $b2b);
            $this->assertInvariants($r, 61561.5, $b2b);
        }
    }

    public function testNoTaxInvoiceIsTreatedAsTaxInclusive(): void
    {
        // 97148111000642: tax rate 0 → prices already include 營業稅.
        $rows = [['A', 3, 2400], ['B', 1.2, 1776], ['C', 4.5, 4686]];
        foreach ([true, false] as $b2b) {
            $r = EInvoiceAmountCalculator::calculate(self::lines($rows), 8862, 1.0, false, $b2b);
            $this->assertInvariants($r, 8862, $b2b);
            $this->assertCount(3, $r['items']);
        }
    }

    public function testOtherTaxRatesStillMatchTheInvoiceTotal(): void
    {
        // Any invoice-level rate (e.g. 10%, or 5% + 2%): total is still the invoice total.
        foreach ([[1.10, 28732], [1.07, 27948], [1.0, 26120]] as [$mult, $total]) {
            foreach ([true, false] as $b2b) {
                $r = EInvoiceAmountCalculator::calculate(self::lines(self::LINES_97148110300627), $total, $mult, false, $b2b);
                $this->assertInvariants($r, $total, $b2b);
            }
        }
    }

    public function testSurchargeBeyondRoundingGetsExplicitLine(): void
    {
        $r = EInvoiceAmountCalculator::calculate(self::lines([['A', 1, 1000]]), 1350, 1.05, false, false);
        $this->assertInvariants($r, 1350, false);
        $this->assertSame(EInvoiceAmountCalculator::OTHER_LABEL, end($r['items'])['description']);
        $this->assertSame(300, end($r['items'])['amount']);
    }

    public function testRoundingThatCannotBeAbsorbedGetsRoundingLine(): void
    {
        // Single 3-qty line: 3 × 840 = 2520; total 2521 can't be split over 3 exactly.
        $r = EInvoiceAmountCalculator::calculate(self::lines([['A', 3, 2400]]), 2521, 1.05, false, false);
        $this->assertInvariants($r, 2521, false);
        $this->assertSame(EInvoiceAmountCalculator::ROUNDING_LABEL, end($r['items'])['description']);
    }

    public function testQuantityZeroLinesAreSkipped(): void
    {
        $r = EInvoiceAmountCalculator::calculate(self::lines([['A', 2, 2000], ['Gone', 0, 0]]), 2100, 1.05, false, false);
        $this->assertInvariants($r, 2100, false);
        $this->assertCount(1, $r['items']);
    }

    public function testNonPositiveTotalIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EInvoiceAmountCalculator::calculate(self::lines([['A', 1, 0]]), 0, 1.05, false, false);
    }
}
