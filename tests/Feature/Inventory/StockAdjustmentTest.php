<?php

namespace Tests\Feature\Inventory;

use App\Livewire\Inventory\StockAdjustmentManager;
use App\Models\Ingredient;
use App\Models\IngredientBalance;
use App\Models\IngredientCategory;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\StockAdjustmentService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class StockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $cashier;

    protected Ingredient $ingredient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->owner = User::where('email', 'owner@temupulang.local')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();

        $category = IngredientCategory::create([
            'name' => 'Coffee Beans',
            'slug' => 'coffee-beans',
            'sort_order' => 1,
        ]);
        $this->ingredient = Ingredient::create([
            'category_id' => $category->id,
            'sku' => 'ING-TEST-001',
            'name' => 'Arabica House Blend',
            'unit' => 'kg',
            'cost_per_unit' => 150000,
            'min_stock_alert' => '1.000',
            'is_active' => true,
        ]);
    }

    public function test_stock_in_creates_missing_balance_and_records_movement_and_audit(): void
    {
        $this->assertSame(0, IngredientBalance::where('ingredient_id', $this->ingredient->id)->count());

        $component = Livewire::actingAs($this->owner)
            ->test(StockAdjustmentManager::class)
            ->assertStatus(200)
            ->call('selectIngredient', $this->ingredient->id)
            ->assertSet('unitCost', 150000)
            ->set('quantity', '2,500')
            ->set('unitCost', 165000)
            ->set('notes', 'PT Kopi Nusantara')
            ->call('submitStockIn')
            ->assertHasNoErrors();

        $balance = IngredientBalance::whereNull('branch_id')
            ->where('ingredient_id', $this->ingredient->id)
            ->firstOrFail();
        $this->assertSame('2.500', Ingredient::fromMilli($balance->current_stock_milli));

        // Ingredient cost_per_unit must remain unchanged
        $this->assertSame(150000, $this->ingredient->fresh()->cost_per_unit);

        $movement = StockMovement::where('ingredient_id', $this->ingredient->id)->firstOrFail();
        $this->assertSame(StockMovement::TYPE_STOCK_IN, $movement->movement_type);
        $this->assertSame('2.500', Ingredient::fromMilli($movement->quantity_milli));
        $this->assertSame('0.000', Ingredient::fromMilli($movement->balance_before_milli));
        $this->assertSame('2.500', Ingredient::fromMilli($movement->balance_after_milli));
        $this->assertSame(165000, $movement->unit_cost);
        $this->assertSame($this->owner->id, $movement->user_id);

        $component->assertSee('2.500')
            ->assertSee('0.000');

        $this->assertDatabaseHas('activity_logs', [
            'module' => 'inventory',
            'action' => 'stock_in',
            'user_id' => $this->owner->id,
        ]);
    }

    public function test_decimal_precision_0100_plus_0200_equals_0300_and_large_value_handled(): void
    {
        $service = app(StockAdjustmentService::class);

        $service->stockIn($this->ingredient, $this->owner, '0.100', idempotencyKey: 'dec-1');
        $service->stockIn($this->ingredient, $this->owner, '0,200', idempotencyKey: 'dec-2');

        $balance = IngredientBalance::where('ingredient_id', $this->ingredient->id)->firstOrFail();
        $this->assertSame('0.300', Ingredient::fromMilli($balance->current_stock_milli));

        $other = Ingredient::create([
            'category_id' => $this->ingredient->category_id,
            'sku' => 'ING-LARGE-001',
            'name' => 'Water Reserve',
            'unit' => 'ml',
            'cost_per_unit' => 10,
            'min_stock_alert' => '0.000',
            'is_active' => true,
        ]);

        $service->stockIn($other, $this->owner, '999999999.999', idempotencyKey: 'large-1');
        $largeBalance = IngredientBalance::where('ingredient_id', $other->id)->firstOrFail();
        $this->assertSame('999999999.999', $largeBalance->current_stock);
    }

    public function test_invalid_quantities_and_zero_for_stock_in_or_wastage_are_rejected(): void
    {
        $component = Livewire::actingAs($this->owner)
            ->test(StockAdjustmentManager::class)
            ->call('selectIngredient', $this->ingredient->id);

        foreach (['abc', '-1.5', '0', '1.2345', '1.000.000'] as $bad) {
            $component->set('quantity', $bad)
                ->call('submitStockIn')
                ->assertSet('errorMessage', __('messages.stock.error_invalid_quantity'));
        }

        $component->call('switchTab', 'wastage')
            ->set('notes', 'Expired')
            ->set('quantity', '0')
            ->call('submitWastage')
            ->assertSet('errorMessage', __('messages.stock.error_invalid_quantity'));

        $this->assertSame(0, StockMovement::count());
    }

    public function test_wastage_requires_reason_reduces_stock_and_rejects_beyond_stock(): void
    {
        app(StockAdjustmentService::class)->stockIn($this->ingredient, $this->owner, '5.000', idempotencyKey: 'seed-5');

        $component = Livewire::actingAs($this->owner)
            ->test(StockAdjustmentManager::class)
            ->call('selectIngredient', $this->ingredient->id)
            ->call('switchTab', 'wastage')
            ->set('quantity', '1.500')
            ->set('notes', '')
            ->call('submitWastage')
            ->assertSet('errorMessage', __('messages.stock.error_reason_required'));

        // Beyond stock rejected
        $component->set('quantity', '6.000')
            ->set('notes', 'Spilled')
            ->call('submitWastage')
            ->assertSet('errorMessage', __('messages.stock.error_insufficient_stock'));

        $this->assertSame(5000, IngredientBalance::where('ingredient_id', $this->ingredient->id)->firstOrFail()->current_stock_milli);
        $this->assertSame(1, StockMovement::count());

        // Valid wastage
        $component->set('quantity', '1,250')
            ->set('notes', 'Spilled during grind')
            ->call('submitWastage');

        $this->assertSame(3750, IngredientBalance::where('ingredient_id', $this->ingredient->id)->firstOrFail()->current_stock_milli);

        $wastage = StockMovement::where('movement_type', StockMovement::TYPE_WASTAGE)->firstOrFail();
        $this->assertSame('-1.250', Ingredient::fromMilli($wastage->quantity_milli));
        $this->assertSame('5.000', Ingredient::fromMilli($wastage->balance_before_milli));
        $this->assertSame('3.750', Ingredient::fromMilli($wastage->balance_after_milli));

        $this->assertDatabaseHas('activity_logs', [
            'module' => 'inventory',
            'action' => 'stock_wastage',
            'user_id' => $this->owner->id,
        ]);
    }

    public function test_adjustment_counted_and_difference_modes_and_zero_difference(): void
    {
        app(StockAdjustmentService::class)->stockIn($this->ingredient, $this->owner, '10.000', idempotencyKey: 'seed-10');

        $component = Livewire::actingAs($this->owner)
            ->test(StockAdjustmentManager::class)
            ->call('selectIngredient', $this->ingredient->id)
            ->call('switchTab', 'adjustment');

        // Counted mode -> target 8.500 => delta -1.500
        $component->set('adjustmentMode', 'counted')
            ->set('quantity', '8.500')
            ->set('notes', 'Physical stock opname')
            ->call('submitAdjustment');

        $this->assertSame(8500, IngredientBalance::where('ingredient_id', $this->ingredient->id)->firstOrFail()->current_stock_milli);

        // Zero difference creates no movement
        $countBefore = StockMovement::count();
        $component->set('adjustmentMode', 'counted')
            ->set('quantity', '8.500')
            ->set('notes', 'Recount no change')
            ->call('submitAdjustment')
            ->assertSet('infoMessage', __('messages.stock.nothing_to_change'));
        $this->assertSame($countBefore, StockMovement::count());

        // Difference mode positive +1.500 => 10.000
        $component->set('adjustmentMode', 'difference')
            ->set('quantity', '1.500')
            ->set('notes', 'Found unopened pack')
            ->call('submitAdjustment');
        $this->assertSame(10000, IngredientBalance::where('ingredient_id', $this->ingredient->id)->firstOrFail()->current_stock_milli);

        // Difference mode negative beyond stock (-11.000) rejected
        $component->set('adjustmentMode', 'difference')
            ->set('quantity', '-11.000')
            ->set('notes', 'Invalid negative')
            ->call('submitAdjustment')
            ->assertSet('errorMessage', __('messages.stock.error_insufficient_stock'));
        $this->assertSame(10000, IngredientBalance::where('ingredient_id', $this->ingredient->id)->firstOrFail()->current_stock_milli);

        $this->assertDatabaseHas('activity_logs', [
            'module' => 'inventory',
            'action' => 'stock_adjustment',
            'user_id' => $this->owner->id,
        ]);
    }

    public function test_idempotent_retry_creates_no_duplicate_movement_and_stock_movement_is_immutable(): void
    {
        $service = app(StockAdjustmentService::class);
        $key = (string) Str::uuid();

        $first = $service->stockIn($this->ingredient, $this->owner, '3.000', idempotencyKey: $key);
        $second = $service->stockIn($this->ingredient, $this->owner, '3.000', idempotencyKey: $key);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(3000, IngredientBalance::where('ingredient_id', $this->ingredient->id)->firstOrFail()->current_stock_milli);

        $this->assertThrows(fn () => $first->update(['notes' => 'tampered']), DomainException::class);
        $this->assertThrows(fn () => $first->delete(), DomainException::class);
    }

    public function test_cashier_blocked_raw_exception_masked_and_both_locales_and_lang_keys_identical(): void
    {
        $response = $this->actingAs($this->cashier)
            ->get(route('inventory.stock'));
        $this->assertTrue(in_array($response->status(), [302, 403], true));

        Livewire::actingAs($this->cashier)
            ->test(StockAdjustmentManager::class)
            ->assertForbidden();

        // Raw exception text never shown
        $this->mock(StockAdjustmentService::class, function ($mock) {
            $mock->shouldReceive('stockIn')
                ->once()
                ->andThrow(new \RuntimeException('SECRET_DB_STACK_TRACE_LEAK'));
        });

        Livewire::actingAs($this->owner)
            ->test(StockAdjustmentManager::class)
            ->call('selectIngredient', $this->ingredient->id)
            ->set('quantity', '1.000')
            ->call('submitStockIn')
            ->assertSet('errorMessage', __('messages.stock.error_generic'))
            ->assertDontSee('SECRET_DB_STACK_TRACE_LEAK');

        // Both locales render
        App::setLocale('id');
        Livewire::actingAs($this->owner)
            ->test(StockAdjustmentManager::class)
            ->assertSee('Stok Masuk & Penyesuaian');

        App::setLocale('en');
        Livewire::actingAs($this->owner)
            ->test(StockAdjustmentManager::class)
            ->assertSee('Stock In & Adjustment');

        // lang/id and lang/en key sets are identical
        $idKeys = array_keys(Arr::dot(require base_path('lang/id/messages.php')));
        $enKeys = array_keys(Arr::dot(require base_path('lang/en/messages.php')));
        sort($idKeys);
        sort($enKeys);
        $this->assertSame($idKeys, $enKeys);
    }
}
