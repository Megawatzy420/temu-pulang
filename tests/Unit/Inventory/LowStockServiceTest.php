<?php

namespace Tests\Unit\Inventory;

use App\Models\Ingredient;
use App\Models\IngredientBalance;
use App\Models\IngredientCategory;
use App\Services\Inventory\LowStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LowStockServiceTest extends TestCase
{
    use RefreshDatabase;

    protected LowStockService $service;

    protected IngredientCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LowStockService::class);
        $this->category = IngredientCategory::create([
            'name' => 'Dry Goods',
            'slug' => 'dry-goods',
            'sort_order' => 1,
        ]);
    }

    public function test_threshold_and_level_classification_and_exclusions(): void
    {
        // Above threshold -> NOT listed
        $above = Ingredient::create(['category_id' => $this->category->id, 'sku' => 'LS-ABOVE', 'name' => 'Above', 'unit' => 'kg', 'cost_per_unit' => 1000, 'min_stock_alert' => '1.000', 'is_active' => true]);
        IngredientBalance::create(['branch_id' => null, 'ingredient_id' => $above->id, 'current_stock' => '1.001']);

        // Equal to threshold (0.100 vs 0.100) -> low
        $equal = Ingredient::create(['category_id' => $this->category->id, 'sku' => 'LS-EQUAL', 'name' => 'Equal', 'unit' => 'kg', 'cost_per_unit' => 1000, 'min_stock_alert' => '0.100', 'is_active' => true]);
        IngredientBalance::create(['branch_id' => null, 'ingredient_id' => $equal->id, 'current_stock' => '0.100']);

        // Below threshold -> low
        $below = Ingredient::create(['category_id' => $this->category->id, 'sku' => 'LS-BELOW', 'name' => 'Below', 'unit' => 'kg', 'cost_per_unit' => 1000, 'min_stock_alert' => '2.000', 'is_active' => true]);
        IngredientBalance::create(['branch_id' => null, 'ingredient_id' => $below->id, 'current_stock' => '0.500']);

        // Zero -> out
        $zero = Ingredient::create(['category_id' => $this->category->id, 'sku' => 'LS-ZERO', 'name' => 'Zero', 'unit' => 'kg', 'cost_per_unit' => 1000, 'min_stock_alert' => '1.000', 'is_active' => true]);
        IngredientBalance::create(['branch_id' => null, 'ingredient_id' => $zero->id, 'current_stock' => '0.000']);

        // Negative -> out
        $negative = Ingredient::create(['category_id' => $this->category->id, 'sku' => 'LS-NEG', 'name' => 'Negative', 'unit' => 'kg', 'cost_per_unit' => 1000, 'min_stock_alert' => '1.000', 'is_active' => true]);
        IngredientBalance::create(['branch_id' => null, 'ingredient_id' => $negative->id, 'current_stock' => '-0.500']);

        // Missing balance row with threshold > 0 -> out
        $missing = Ingredient::create(['category_id' => $this->category->id, 'sku' => 'LS-MISS', 'name' => 'Missing', 'unit' => 'kg', 'cost_per_unit' => 1000, 'min_stock_alert' => '0.500', 'is_active' => true]);

        // Threshold 0 -> NEVER listed (even with 0 stock)
        Ingredient::create(['category_id' => $this->category->id, 'sku' => 'LS-ZERO-THRESH', 'name' => 'NoAlert', 'unit' => 'kg', 'cost_per_unit' => 1000, 'min_stock_alert' => '0.000', 'is_active' => true]);

        // Inactive -> excluded
        Ingredient::create(['category_id' => $this->category->id, 'sku' => 'LS-INACT', 'name' => 'Inactive', 'unit' => 'kg', 'cost_per_unit' => 1000, 'min_stock_alert' => '5.000', 'is_active' => false]);

        // Soft-deleted -> excluded
        $deleted = Ingredient::create(['category_id' => $this->category->id, 'sku' => 'LS-DEL', 'name' => 'Deleted', 'unit' => 'kg', 'cost_per_unit' => 1000, 'min_stock_alert' => '5.000', 'is_active' => true]);
        $deleted->delete();

        $results = $this->service->lowStockIngredients();
        $bySku = $results->keyBy('sku');

        $this->assertSame(5, $results->count());
        $this->assertArrayNotHasKey('LS-ABOVE', $bySku->toArray());
        $this->assertArrayNotHasKey('LS-ZERO-THRESH', $bySku->toArray());
        $this->assertArrayNotHasKey('LS-INACT', $bySku->toArray());
        $this->assertArrayNotHasKey('LS-DEL', $bySku->toArray());

        $this->assertSame('low', $bySku['LS-EQUAL']['level']);
        $this->assertSame('low', $bySku['LS-BELOW']['level']);
        $this->assertSame('out', $bySku['LS-ZERO']['level']);
        $this->assertSame('out', $bySku['LS-NEG']['level']);
        $this->assertSame('out', $bySku['LS-MISS']['level']);

        // Ordering: "out" first, then smallest (current_stock - min_stock_alert), then name:
        // Out items:
        //   Negative (-0.5 - 1.0 = -1.5)
        //   Zero (0 - 1.0 = -1.0)
        //   Missing (0 - 0.5 = -0.5)
        // Low items:
        //   Below (0.5 - 2.0 = -1.5)
        //   Equal (0.1 - 0.1 = 0.0)
        $this->assertSame(
            ['LS-NEG', 'LS-ZERO', 'LS-MISS', 'LS-BELOW', 'LS-EQUAL'],
            $results->pluck('sku')->all()
        );

        // count() matches list and runs in a single SQL query
        DB::enableQueryLog();
        DB::flushQueryLog();
        $count = $this->service->count();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(5, $count);
        $this->assertSame(1, $queryCount);
    }
}
