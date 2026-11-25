<?php

namespace Tests\Unit;

use App\Livewire\Pos\CashierPos;
use PHPUnit\Framework\TestCase;

class PosCashSuggestionTest extends TestCase
{
    public function test_suggest_cash_amounts_for_typical_totals(): void
    {
        // Prompt example: 37.500 -> [40.000, 50.000, 100.000]
        $this->assertSame([40000, 50000, 100000], CashierPos::suggestCashAmounts(37500));

        // Small total: 12.500 -> [15.000, 20.000, 50.000, 100.000]
        $this->assertSame([15000, 20000, 50000, 100000], CashierPos::suggestCashAmounts(12500));

        // Large total: 235.000 -> [240.000, 250.000, 300.000]
        $this->assertSame([240000, 250000, 300000], CashierPos::suggestCashAmounts(235000));
    }

    public function test_suggest_cash_amounts_for_exact_multiples(): void
    {
        // 50.000 -> [60.000, 100.000]
        $this->assertSame([60000, 100000], CashierPos::suggestCashAmounts(50000));

        // 100.000 -> [] (only "Uang Pas" is needed)
        $this->assertSame([], CashierPos::suggestCashAmounts(100000));
    }

    public function test_suggestions_are_unique_ascending_and_never_below_or_equal_to_total(): void
    {
        foreach ([0, 1, 4500, 12500, 37500, 50000, 99999, 100000, 235000, 487000] as $total) {
            $suggestions = CashierPos::suggestCashAmounts($total);

            $this->assertLessThanOrEqual(4, count($suggestions));
            $this->assertSame(array_values(array_unique($suggestions)), $suggestions);

            $sorted = $suggestions;
            sort($sorted);
            $this->assertSame($sorted, $suggestions);

            foreach ($suggestions as $amount) {
                $this->assertGreaterThan($total, $amount);
            }
        }
    }
}
