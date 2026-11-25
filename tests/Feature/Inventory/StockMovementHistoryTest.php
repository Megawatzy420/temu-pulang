<?php

namespace Tests\Feature\Inventory;

use App\Livewire\Inventory\IngredientManager;
use App\Livewire\Inventory\StockMovementHistory;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\Order;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class StockMovementHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $cashier;

    protected Ingredient $beans;

    protected Ingredient $milk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->owner = User::where('email', 'owner@temupulang.local')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();

        $cat = IngredientCategory::create(['name' => 'Cafe', 'slug' => 'cafe-hist', 'sort_order' => 1]);
        $this->beans = Ingredient::create(['category_id' => $cat->id, 'sku' => 'HIS-BEANS', 'name' => 'House Beans', 'unit' => 'kg', 'cost_per_unit' => 120000, 'min_stock_alert' => '1.000', 'is_active' => true]);
        $this->milk = Ingredient::create(['category_id' => $cat->id, 'sku' => 'HIS-MILK', 'name' => 'Whole Milk', 'unit' => 'L', 'cost_per_unit' => 25000, 'min_stock_alert' => '1.000', 'is_active' => true]);
    }

    public function test_newest_first_with_id_tiebreaker_and_pagination_at_25(): void
    {
        $ts = now('Asia/Jakarta');

        for ($i = 1; $i <= 26; $i++) {
            $label = sprintf('Note #%02d', $i);
            StockMovement::create([
                'ulid' => (string) Str::ulid(),
                'branch_id' => null,
                'ingredient_id' => $this->beans->id,
                'user_id' => $this->owner->id,
                'movement_type' => StockMovement::TYPE_STOCK_IN,
                'quantity' => '1.000',
                'balance_before' => '0.000',
                'balance_after' => '1.000',
                'unit_cost' => 120000,
                'notes' => $label,
                'created_at' => $ts,
            ]);
        }

        // First page shows 25 newest (id 26 down to id 2); id 1 is on page 2
        $component = Livewire::actingAs($this->owner)
            ->test(StockMovementHistory::class)
            ->assertStatus(200)
            ->assertSee('Note #26')
            ->assertSee('Note #02')
            ->assertDontSee('Note #01');

        $html = $component->html();
        $this->assertLessThan(strpos($html, 'Note #25'), strpos($html, 'Note #26'));
    }

    public function test_filters_summary_totals_and_invalid_input_handling(): void
    {
        // 0.100 + 0.200 = 0.300 in, -0.050 wastage out => net +0.250
        $m1 = StockMovement::forceCreate([
            'ulid' => (string) Str::ulid(),
            'ingredient_id' => $this->beans->id,
            'user_id' => $this->owner->id,
            'movement_type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => '0.100',
            'balance_before' => '0.000',
            'balance_after' => '0.100',
            'unit_cost' => 120000,
            'notes' => 'Beans in 1',
            'created_at' => '2026-11-20 10:00:00',
        ]);
        $m2 = StockMovement::forceCreate([
            'ulid' => (string) Str::ulid(),
            'ingredient_id' => $this->beans->id,
            'user_id' => $this->cashier->id,
            'movement_type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => '0.200',
            'balance_before' => '0.100',
            'balance_after' => '0.300',
            'unit_cost' => 120000,
            'notes' => 'Beans in 2',
            'created_at' => '2026-11-21 12:00:00',
        ]);
        $m3 = StockMovement::forceCreate([
            'ulid' => (string) Str::ulid(),
            'ingredient_id' => $this->beans->id,
            'user_id' => $this->owner->id,
            'movement_type' => StockMovement::TYPE_WASTAGE,
            'quantity' => '-0.050',
            'balance_before' => '0.300',
            'balance_after' => '0.250',
            'unit_cost' => 120000,
            'notes' => 'Beans spilled',
            'created_at' => '2026-11-22 14:00:00',
        ]);
        StockMovement::forceCreate([
            'ulid' => (string) Str::ulid(),
            'ingredient_id' => $this->milk->id,
            'user_id' => $this->owner->id,
            'movement_type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => '2.000',
            'balance_before' => '0.000',
            'balance_after' => '2.000',
            'unit_cost' => 25000,
            'notes' => 'Milk in',
            'created_at' => '2026-11-21 15:00:00',
        ]);

        // Without ingredient filter -> no totals shown
        $component = Livewire::actingAs($this->owner)
            ->test(StockMovementHistory::class)
            ->assertDontSee(__('messages.stock_history.summary_total_in'));

        // Select single ingredient -> totals appear with exact 3 decimals
        $component->set('ingredientId', (string) $this->beans->id)
            ->assertSee(__('messages.stock_history.summary_total_in'))
            ->assertSee('+0.300 kg')
            ->assertSee('-0.050 kg')
            ->assertSee('+0.250 kg')
            ->assertDontSee('Milk in');

        // Combine with type filter
        $component->set('movementType', StockMovement::TYPE_WASTAGE)
            ->assertSee('Beans spilled')
            ->assertDontSee('Beans in 1');

        // Reset and test date range (inclusive boundaries) + actor
        $component->call('resetFilters')
            ->set('dateFrom', '2026-11-21')
            ->set('dateTo', '2026-11-21')
            ->set('actorId', (string) $this->cashier->id)
            ->assertSee('Beans in 2')
            ->assertDontSee('Beans in 1')
            ->assertDontSee('Milk in');

        // Invalid filter inputs never error (500)
        $component->set('dateFrom', 'not-a-date')
            ->set('dateTo', '2026-99-99')
            ->set('ingredientId', 'abc')
            ->set('movementType', 'unknown_type')
            ->set('actorId', 'invalid')
            ->assertStatus(200)
            ->assertSee('Beans in 1');

        // Verify exact id variables used cleanly
        $this->assertNotNull($m1->id);
        $this->assertNotNull($m2->id);
        $this->assertNotNull($m3->id);
    }

    public function test_order_reference_unresolved_reference_and_soft_deleted_ingredient_and_user_display(): void
    {
        $shift = Shift::create([
            'shift_number' => 'SHF-HIS-1',
            'user_id' => $this->cashier->id,
            'status' => 'open',
            'opening_cash' => 100000,
            'opened_at' => now('Asia/Jakarta'),
        ]);
        $cat = Category::firstOrFail();
        $order = Order::create([
            'ulid' => (string) Str::ulid(),
            'order_number' => 'ORD-HIST-999',
            'daily_sequence' => 1,
            'daily_order_number' => 'TP-999',
            'call_number' => 'TP-999',
            'business_date' => now('Asia/Jakarta')->toDateString(),
            'order_type' => Order::TYPE_DINE_IN,
            'status' => Order::STATUS_PAID,
            'payment_status' => Order::PAYMENT_STATUS_PAID,
            'subtotal' => 20000,
            'tax_amount' => 0,
            'service_charge_amount' => 0,
            'total_amount' => 20000,
            'user_id' => $this->cashier->id,
            'shift_id' => $shift->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->assertNotNull($cat->id);

        $deletedUser = User::factory()->cashier()->create(['name' => 'Former Cashier']);
        $deletedUser->delete();

        $deletedIngredient = Ingredient::create([
            'category_id' => $this->beans->category_id,
            'sku' => 'HIS-DEL',
            'name' => 'Retired Syrup',
            'unit' => 'ml',
            'cost_per_unit' => 50,
            'min_stock_alert' => '0.000',
        ]);
        $deletedIngredient->delete();

        // Resolved order reference + soft-deleted ingredient & user
        StockMovement::create([
            'ulid' => (string) Str::ulid(),
            'ingredient_id' => $deletedIngredient->id,
            'user_id' => $deletedUser->id,
            'movement_type' => StockMovement::TYPE_ORDER_DEDUCTION,
            'quantity' => '-10.000',
            'balance_before' => '100.000',
            'balance_after' => '90.000',
            'unit_cost' => 50,
            'reference_type' => 'orders',
            'reference_id' => $order->id,
            'notes' => 'Resolved order row',
        ]);

        // Unresolved order reference
        StockMovement::create([
            'ulid' => (string) Str::ulid(),
            'ingredient_id' => $this->beans->id,
            'user_id' => $this->owner->id,
            'movement_type' => StockMovement::TYPE_ORDER_DEDUCTION,
            'quantity' => '-0.100',
            'balance_before' => '1.000',
            'balance_after' => '0.900',
            'unit_cost' => 120000,
            'reference_type' => 'orders',
            'reference_id' => 999999,
            'notes' => 'Missing order row',
        ]);

        Livewire::actingAs($this->owner)
            ->test(StockMovementHistory::class)
            ->assertSee('Retired Syrup')
            ->assertSee('Former Cashier')
            ->assertSee('ORD-HIST-999')
            ->assertSee(__('messages.stock_history.ref_unavailable'));
    }

    public function test_query_count_stays_constant_for_5_vs_25_rows(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            StockMovement::create([
                'ulid' => (string) Str::ulid(),
                'ingredient_id' => $this->beans->id,
                'user_id' => $this->owner->id,
                'movement_type' => StockMovement::TYPE_STOCK_IN,
                'quantity' => '1.000',
                'balance_before' => '0.000',
                'balance_after' => '1.000',
                'unit_cost' => 120000,
            ]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        Livewire::actingAs($this->owner)->test(StockMovementHistory::class);
        $queriesAt5 = count(DB::getQueryLog());
        DB::disableQueryLog();

        for ($i = 6; $i <= 25; $i++) {
            StockMovement::create([
                'ulid' => (string) Str::ulid(),
                'ingredient_id' => $this->milk->id,
                'user_id' => $this->cashier->id,
                'movement_type' => StockMovement::TYPE_STOCK_IN,
                'quantity' => '1.000',
                'balance_before' => '0.000',
                'balance_after' => '1.000',
                'unit_cost' => 25000,
            ]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        Livewire::actingAs($this->owner)->test(StockMovementHistory::class);
        $queriesAt25 = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(10, $queriesAt25);
        $this->assertLessThanOrEqual($queriesAt5 + 1, $queriesAt25);
    }

    public function test_rbac_locales_read_only_nature_ingredient_link_and_lang_keys(): void
    {
        $res = $this->actingAs($this->cashier)->get(route('inventory.movements'));
        $this->assertTrue(in_array($res->status(), [302, 403], true));

        Livewire::actingAs($this->cashier)
            ->test(StockMovementHistory::class)
            ->assertForbidden();

        // Cashier sees no sidebar link
        $this->actingAs($this->cashier)
            ->get(route('pos'))
            ->assertDontSee(route('inventory.movements'));

        // IngredientManager row has History link preselecting the ingredient
        Livewire::actingAs($this->owner)
            ->test(IngredientManager::class)
            ->assertSee(route('inventory.movements', ['ingredient' => $this->beans->id]));

        // Both locales render
        App::setLocale('id');
        Livewire::actingAs($this->owner)
            ->test(StockMovementHistory::class)
            ->assertSee('Riwayat Pergerakan Stok');

        App::setLocale('en');
        Livewire::actingAs($this->owner)
            ->test(StockMovementHistory::class)
            ->assertSee('Stock Movement History');

        // No mutating methods on StockMovementHistory
        $methods = get_class_methods(StockMovementHistory::class);
        foreach (['create', 'store', 'edit', 'update', 'delete', 'destroy'] as $mutator) {
            $this->assertNotContains($mutator, $methods);
        }

        // lang/id and lang/en key sets are identical
        $idKeys = array_keys(Arr::dot(require base_path('lang/id/messages.php')));
        $enKeys = array_keys(Arr::dot(require base_path('lang/en/messages.php')));
        sort($idKeys);
        sort($enKeys);
        $this->assertSame($idKeys, $enKeys);
    }
}
