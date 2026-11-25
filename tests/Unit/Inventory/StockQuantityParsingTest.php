<?php

namespace Tests\Unit\Inventory;

use App\Exceptions\Inventory\InvalidStockQuantityException;
use App\Models\Ingredient;
use App\Services\Inventory\StockAdjustmentService;
use PHPUnit\Framework\TestCase;

class StockQuantityParsingTest extends TestCase
{
    public function test_comma_and_dot_decimals_parse_identically_and_one_dot_three_zeros_is_one(): void
    {
        $this->assertSame(1000, StockAdjustmentService::parseQuantityToMilli('1.000'));
        $this->assertSame(1000, StockAdjustmentService::parseQuantityToMilli('1,000'));
        $this->assertSame(1500, StockAdjustmentService::parseQuantityToMilli('1.5'));
        $this->assertSame(1500, StockAdjustmentService::parseQuantityToMilli('1,5'));
        $this->assertSame(100, StockAdjustmentService::parseQuantityToMilli('0.100'));
        $this->assertSame(200, StockAdjustmentService::parseQuantityToMilli('0,200'));
        $this->assertSame('0.300', Ingredient::fromMilli(100 + 200));
        $this->assertSame(999999999999, StockAdjustmentService::parseQuantityToMilli('999999999.999'));
    }

    public function test_negative_allowed_only_when_requested(): void
    {
        $this->assertSame(-2500, StockAdjustmentService::parseQuantityToMilli('-2,5', allowNegative: true));

        $this->expectException(InvalidStockQuantityException::class);
        StockAdjustmentService::parseQuantityToMilli('-2.5', allowNegative: false);
    }

    public function test_invalid_inputs_are_rejected(): void
    {
        $invalidInputs = ['', '   ', 'abc', '1.2345', '1.000.000', '1,000.500', '10 000'];

        foreach ($invalidInputs as $bad) {
            try {
                StockAdjustmentService::parseQuantityToMilli($bad, allowNegative: true);
                $this->fail("Expected InvalidStockQuantityException for '{$bad}'");
            } catch (InvalidStockQuantityException $e) {
                $this->assertInstanceOf(InvalidStockQuantityException::class, $e);
            }
        }
    }
}
