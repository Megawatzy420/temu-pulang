<?php

namespace Tests\Feature\Inventory;

use App\Livewire\Dashboard\OwnerDashboard;
use App\Livewire\Inventory\IngredientManager;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientBalance;
use App\Models\IngredientCategory;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\RecipeItem;
use App\Models\User;
use App\Services\Inventory\LowStockService;
use App\Services\Inventory\StockAdjustmentService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class LowStockAlertTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $cashier;

    protected IngredientCategory $catA;

    protected IngredientCategory $catB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->owner = User::where('email', 'owner@temupulang.local')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();

        $this->catA = IngredientCategory::create(['name' => 'Beans', 'slug' => 'beans-ls', 'sort_order' => 1]);
        $this->catB = IngredientCategory::create(['name' => 'Dairy', 'slug' => 'dairy-ls', 'sort_order' => 2]);
    }

    public function test_ingredient_manager_shows_badges_and_three_way_filter_with_search_and_category(): void
    {
        $okItem = Ingredient::create(['category_id' => $this->catA->id, 'sku' => 'LS-OK', 'name' => 'Alpha Ok Beans', 'unit' => 'kg', 'cost_per_unit' => 1000, 'min_stock_alert' => '1.000', 'is_active' => true]);
        IngredientBalance::create(['branch_id' => null, 'ingredient_id' => $okItem->id, 'current_stock' => '5.000']);

        $lowItem = Ingredient::create(['category_id' => $this->catA->id, 'sku' => 'LS-LOW', 'name' => 'Beta Low Beans', 'unit' => 'kg', 'cost_per_unit' => 1000, 'min_stock_alert' => '2.000', 'is_active' => true]);
        IngredientBalance::create(['branch_id' => null, 'ingredient_id' => $lowItem->id, 'current_stock' => '1.000']);

        $outItem = Ingredient::create(['category_id' => $this->catB->id, 'sku' => 'LS-OUT', 'name' => 'Gamma Out Milk', 'unit' => 'L', 'cost_per_unit' => 1000, 'min_stock_alert' => '1.000', 'is_active' => true]);
        IngredientBalance::create(['branch_id' => null, 'ingredient_id' => $outItem->id, 'current_stock' => '0.000']);

        $component = Livewire::actingAs($this->owner)
            ->test(IngredientManager::class)
            ->assertSee('Alpha Ok Beans')
            ->assertSee('Beta Low Beans')
            ->assertSee('Gamma Out Milk')
            ->assertSee(__('messages.stock_alert.badge_ok'))
            ->assertSee(__('messages.stock_alert.badge_low'))
            ->assertSee(__('messages.stock_alert.badge_out'));

        // Filter: low_or_out
        $component->set('stockFilter', 'low_or_out')
            ->assertDontSee('Alpha Ok Beans')
            ->assertSee('Beta Low Beans')
            ->assertSee('Gamma Out Milk');

        // Combined with category filter (Beans only)
        $component->set('selectedCategoryId', $this->catA->id)
            ->assertSee('Beta Low Beans')
            ->assertDontSee('Gamma Out Milk');

        // Filter: out only + clear category
        $component->set('selectedCategoryId', null)
            ->set('stockFilter', 'out')
            ->assertDontSee('Alpha Ok Beans')
            ->assertDontSee('Beta Low Beans')
            ->assertSee('Gamma Out Milk');

        // Combined with search
        $component->set('search', 'Alpha')
            ->assertDontSee('Gamma Out Milk');
    }

    public function test_owner_sees_sidebar_badge_and_dashboard_card_while_cashier_is_blocked(): void
    {
        // Initially 0 low stock -> sidebar badge hidden, dashboard shows empty state
        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertStatus(200)
            ->assertSee(__('messages.stock_alert.dashboard_title'))
            ->assertSee(__('messages.stock_alert.empty_state'));

        // Create 2 low/out ingredients -> badge = 2, dashboard shows items + View all link
        $low = Ingredient::create(['category_id' => $this->catA->id, 'sku' => 'LS-D1', 'name' => 'Espresso Low', 'unit' => 'kg', 'cost_per_unit' => 1000, 'min_stock_alert' => '2.000', 'is_active' => true]);
        IngredientBalance::create(['branch_id' => null, 'ingredient_id' => $low->id, 'current_stock' => '1.000']);
        Ingredient::create(['category_id' => $this->catB->id, 'sku' => 'LS-D2', 'name' => 'Milk Out', 'unit' => 'L', 'cost_per_unit' => 1000, 'min_stock_alert' => '1.000', 'is_active' => true]);

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertStatus(200)
            ->assertSee('Espresso Low')
            ->assertSee('Milk Out')
            ->assertSee(route('inventory.ingredients', ['stock' => 'low_or_out']))
            ->assertSee('2');

        // Cashier blocked from dashboard and ingredients, and POS layout has no low-stock badge
        $dashRes = $this->actingAs($this->cashier)->get(route('dashboard'));
        $this->assertTrue(in_array($dashRes->status(), [302, 403], true));

        $ingRes = $this->actingAs($this->cashier)->get(route('inventory.ingredients'));
        $this->assertTrue(in_array($ingRes->status(), [302, 403], true));

        $this->actingAs($this->cashier)
            ->get(route('pos'))
            ->assertStatus(200)
            ->assertDontSee(__('messages.stock_alert.dashboard_title'))
            ->assertDontSee(route('inventory.stock'));
    }

    public function test_stock_in_clears_alert_and_order_deduction_triggers_alert(): void
    {
        $beans = Ingredient::create([
            'category_id' => $this->catA->id,
            'sku' => 'LS-INT-1',
            'name' => 'Integration Beans',
            'unit' => 'kg',
            'cost_per_unit' => 100000,
            'min_stock_alert' => '1.000',
            'is_active' => true,
        ]);
        IngredientBalance::create(['branch_id' => null, 'ingredient_id' => $beans->id, 'current_stock' => '0.500']);

        $lowStock = app(LowStockService::class);
        $this->assertSame(1, $lowStock->count());

        // Stock in +1.000 -> 1.500 (above 1.000 threshold) -> alert disappears
        app(StockAdjustmentService::class)->stockIn($beans, $this->owner, '1.000', idempotencyKey: 'ls-stockin-1');
        $this->assertSame(0, $lowStock->count());

        // Order deduction of 0.600 -> 0.900 (<= 1.000 threshold) -> alert appears
        $cat = Category::firstOrFail();
        $product = Product::create(['category_id' => $cat->id, 'sku' => 'LS-PROD', 'base_price' => 25000, 'has_variants' => false, 'is_active' => true]);
        ProductTranslation::create(['product_id' => $product->id, 'locale' => 'id', 'name' => 'Kopi']);
        ProductTranslation::create(['product_id' => $product->id, 'locale' => 'en', 'name' => 'Coffee']);
        RecipeItem::create(['product_id' => $product->id, 'product_variant_id' => null, 'ingredient_id' => $beans->id, 'quantity' => '0.600']);

        $shift = app(ShiftService::class)->openShift($this->cashier, 100000, 'LS shift');
        $order = app(OrderService::class)->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        app(PaymentService::class)->settlePayment(
            cashier: $this->cashier,
            shift: $shift,
            order: $order,
            tenders: [['payment_method' => 'cash', 'amount' => $order->total_amount, 'tendered_amount' => $order->total_amount, 'change_amount' => 0]],
            idempotencyKey: 'pay_'.Str::uuid()
        );

        $this->assertSame(1, $lowStock->count());
        $this->assertSame('low', $lowStock->lowStockIngredients()->first()['level']);
    }

    public function test_both_locales_render_and_lang_keys_are_identical(): void
    {
        App::setLocale('id');
        Livewire::actingAs($this->owner)
            ->test(OwnerDashboard::class)
            ->assertSee('Peringatan Stok Menipis');

        App::setLocale('en');
        Livewire::actingAs($this->owner)
            ->test(OwnerDashboard::class)
            ->assertSee('Low Stock');

        $idKeys = array_keys(Arr::dot(require base_path('lang/id/messages.php')));
        $enKeys = array_keys(Arr::dot(require base_path('lang/en/messages.php')));
        sort($idKeys);
        sort($enKeys);
        $this->assertSame($idKeys, $enKeys);
    }
}
