<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientBalance;
use App\Models\IngredientCategory;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\RecipeItem;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ShiftService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderDeductionTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected Shift $shift;

    protected Ingredient $coffee;

    protected Ingredient $milk;

    protected Product $latte;

    protected Product $plainWater;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();
        $this->shift = app(ShiftService::class)->openShift(
            user: $this->cashier,
            openingCash: 100000,
            openingNote: 'Order deduction test'
        );

        $ingCat = IngredientCategory::create([
            'name' => 'Bar Ingredients',
            'slug' => 'bar-ingredients',
            'sort_order' => 1,
        ]);

        // Deliberately create coffee first (lower ID) and milk second (higher ID)
        $this->coffee = Ingredient::create([
            'category_id' => $ingCat->id,
            'sku' => 'ING-COF-1',
            'name' => 'House Blend',
            'unit' => 'kg',
            'cost_per_unit' => 180000,
            'min_stock_alert' => '1.000',
        ]);

        $this->milk = Ingredient::create([
            'category_id' => $ingCat->id,
            'sku' => 'ING-MLK-2',
            'name' => 'Full Cream Milk',
            'unit' => 'L',
            'cost_per_unit' => 24000,
            'min_stock_alert' => '1.000',
        ]);

        $cat = Category::firstOrFail();
        $this->latte = Product::create([
            'category_id' => $cat->id,
            'sku' => 'LATTE-DED',
            'base_price' => 30000,
            'has_variants' => false,
            'is_active' => true,
        ]);
        ProductTranslation::create(['product_id' => $this->latte->id, 'locale' => 'id', 'name' => 'Latte Ded']);
        ProductTranslation::create(['product_id' => $this->latte->id, 'locale' => 'en', 'name' => 'Latte Ded']);

        // Base recipe: 0.100 coffee + 0.200 milk
        RecipeItem::create([
            'product_id' => $this->latte->id,
            'product_variant_id' => null,
            'ingredient_id' => $this->coffee->id,
            'quantity' => '0.100',
        ]);
        RecipeItem::create([
            'product_id' => $this->latte->id,
            'product_variant_id' => null,
            'ingredient_id' => $this->milk->id,
            'quantity' => '0.200',
        ]);

        $this->plainWater = Product::create([
            'category_id' => $cat->id,
            'sku' => 'WATER-NO-REC',
            'base_price' => 10000,
            'has_variants' => false,
            'is_active' => true,
        ]);
        ProductTranslation::create(['product_id' => $this->plainWater->id, 'locale' => 'id', 'name' => 'Air Mineral']);
        ProductTranslation::create(['product_id' => $this->plainWater->id, 'locale' => 'en', 'name' => 'Mineral Water']);
    }

    public function test_settling_order_deducts_ingredients_and_updates_balances_and_movements(): void
    {
        IngredientBalance::create([
            'branch_id' => null,
            'ingredient_id' => $this->coffee->id,
            'current_stock' => '5.000',
        ]);
        IngredientBalance::create([
            'branch_id' => null,
            'ingredient_id' => $this->milk->id,
            'current_stock' => '10.000',
        ]);

        $order = app(OrderService::class)->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $this->latte->id, 'quantity' => 3],
            ],
        ]);

        $tx = app(PaymentService::class)->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order,
            tenders: [[
                'payment_method' => 'cash',
                'amount' => $order->total_amount,
                'tendered_amount' => $order->total_amount,
                'change_amount' => 0,
            ]],
            idempotencyKey: 'pay_'.Str::uuid()
        );

        // 0.100 * 3 = 0.300 coffee (5.000 -> 4.700), 0.200 * 3 = 0.600 milk (10.000 -> 9.400)
        $coffeeBal = IngredientBalance::where('ingredient_id', $this->coffee->id)->firstOrFail();
        $milkBal = IngredientBalance::where('ingredient_id', $this->milk->id)->firstOrFail();
        $this->assertSame(4700, $coffeeBal->current_stock_milli);
        $this->assertSame(9400, $milkBal->current_stock_milli);

        $coffeeMov = StockMovement::where('ingredient_id', $this->coffee->id)->firstOrFail();
        $this->assertSame(StockMovement::TYPE_ORDER_DEDUCTION, $coffeeMov->movement_type);
        $this->assertSame(-300, $coffeeMov->quantity_milli);
        $this->assertSame(5000, $coffeeMov->balance_before_milli);
        $this->assertSame(4700, $coffeeMov->balance_after_milli);
        $this->assertSame(180000, $coffeeMov->unit_cost);
        $this->assertSame('orders', $coffeeMov->reference_type);
        $this->assertSame($order->id, $coffeeMov->reference_id);
        $this->assertSame($this->cashier->id, $coffeeMov->user_id);
        $this->assertSame($tx->ulid, $coffeeMov->idempotency_key);

        // Append-only remains enforced
        $this->assertThrows(fn () => $coffeeMov->update(['notes' => 'tamper']), DomainException::class);
        $this->assertThrows(fn () => $coffeeMov->delete(), DomainException::class);
    }

    public function test_split_payment_and_idempotent_replay_deduct_exactly_once(): void
    {
        IngredientBalance::create([
            'branch_id' => null,
            'ingredient_id' => $this->coffee->id,
            'current_stock' => '2.000',
        ]);

        $order = app(OrderService::class)->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $this->latte->id, 'quantity' => 1],
                ['product_id' => $this->latte->id, 'quantity' => 2],
            ],
        ]);

        $paymentService = app(PaymentService::class);
        $qrisConf = $paymentService->createQrisConfirmation(
            order: $order,
            cashier: $this->cashier,
            expectedAmount: $order->total_amount - 30000,
            referenceNumber: 'QRIS-SPLIT'
        );
        $key = 'pay_'.Str::uuid();

        $tenders = [
            ['payment_method' => 'cash', 'amount' => 30000, 'tendered_amount' => 30000, 'change_amount' => 0],
            ['payment_method' => 'qris', 'amount' => $order->total_amount - 30000, 'qris_confirmation_id' => $qrisConf->id],
        ];

        $paymentService->settlePayment($this->cashier, $this->shift, $order, $tenders, $key);
        // Replay with exact same key
        $paymentService->settlePayment($this->cashier, $this->shift, $order, $tenders, $key);

        // Multiple items sharing ingredient produce ONE movement per ingredient
        $this->assertSame(1, StockMovement::where('ingredient_id', $this->coffee->id)->count());
        $this->assertSame(1, StockMovement::where('ingredient_id', $this->milk->id)->count());

        // 0.100 * 3 = 0.300 => 2.000 - 0.300 = 1.700
        $this->assertSame(1700, IngredientBalance::where('ingredient_id', $this->coffee->id)->firstOrFail()->current_stock_milli);
        // Missing milk balance created automatically and deducted: 0.000 - 0.600 = -0.600
        $this->assertSame(-600, IngredientBalance::where('ingredient_id', $this->milk->id)->firstOrFail()->current_stock_milli);
    }

    public function test_product_without_recipe_creates_no_movements_and_payment_succeeds(): void
    {
        $order = app(OrderService::class)->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_TAKEAWAY,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $this->plainWater->id, 'quantity' => 2]],
        ]);

        $tx = app(PaymentService::class)->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order,
            tenders: [['payment_method' => 'cash', 'amount' => $order->total_amount, 'tendered_amount' => $order->total_amount, 'change_amount' => 0]],
            idempotencyKey: 'pay_'.Str::uuid()
        );

        $this->assertSame('completed', $tx->status);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_deduction_failure_does_not_block_payment_or_printing_and_retry_command_recovers_only_failed(): void
    {
        // Pre-existing settled order from before feature (no order_deduction_failed log)
        $legacyOrder = app(OrderService::class)->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $this->plainWater->id, 'quantity' => 1]],
        ]);
        $legacyTx = PaymentTransaction::create([
            'ulid' => (string) Str::ulid(),
            'payment_transaction_number' => 'PAY-LEGACY-0001',
            'daily_sequence' => 999,
            'business_date' => now('Asia/Jakarta')->toDateString(),
            'order_id' => $legacyOrder->id,
            'shift_id' => $this->shift->id,
            'user_id' => $this->cashier->id,
            'cashier_name' => $this->cashier->name,
            'total_amount' => $legacyOrder->total_amount,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'legacy'),
            'status' => 'completed',
        ]);

        // Mock InventoryService to throw during settlement
        $this->mock(InventoryService::class, function ($mock) {
            $mock->shouldReceive('deductForPayment')
                ->once()
                ->andThrow(new \RuntimeException('Simulated inventory lock failure'));
        });

        $order = app(OrderService::class)->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $this->latte->id, 'quantity' => 2]],
        ]);

        $tx = app(PaymentService::class)->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order,
            tenders: [['payment_method' => 'cash', 'amount' => $order->total_amount, 'tendered_amount' => $order->total_amount, 'change_amount' => 0]],
            idempotencyKey: 'pay_'.Str::uuid()
        );

        // Payment still settled and print job queued
        $this->assertSame(Order::PAYMENT_STATUS_PAID, $order->fresh()->payment_status);
        $this->assertSame(1, PrintJob::where('order_id', $order->id)->count());
        $this->assertSame(0, StockMovement::count());

        $this->assertDatabaseHas('activity_logs', [
            'module' => 'inventory',
            'action' => 'order_deduction_failed',
            'subject_id' => $tx->id,
        ]);

        // Restore real InventoryService for retry command
        $this->app->forgetInstance(InventoryService::class);

        // Dry run lists pending failed deduction and creates no movements
        $this->artisan('inventory:retry-deductions')
            ->expectsOutputToContain($tx->payment_transaction_number)
            ->doesntExpectOutputToContain($legacyTx->payment_transaction_number)
            ->assertExitCode(0);
        $this->assertSame(0, StockMovement::count());

        // --apply deducts it once
        $this->artisan('inventory:retry-deductions --apply')
            ->expectsOutputToContain($tx->payment_transaction_number)
            ->assertExitCode(0);
        $this->assertSame(2, StockMovement::count());
        $this->assertSame(-200, IngredientBalance::where('ingredient_id', $this->coffee->id)->firstOrFail()->current_stock_milli);

        // Second --apply does nothing
        $this->artisan('inventory:retry-deductions --apply')
            ->expectsOutputToContain('No pending failed deductions remain.')
            ->assertExitCode(0);
        $this->assertSame(2, StockMovement::count());
    }

    public function test_balances_are_locked_in_ascending_ingredient_id_order(): void
    {
        $order = app(OrderService::class)->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $this->latte->id, 'quantity' => 1]],
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        app(PaymentService::class)->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order,
            tenders: [['payment_method' => 'cash', 'amount' => $order->total_amount, 'tendered_amount' => $order->total_amount, 'change_amount' => 0]],
            idempotencyKey: 'pay_'.Str::uuid()
        );

        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $balanceLockQuery = $queries->first(
            static fn (string $sql): bool => strpos($sql, 'ingredient_balances') !== false && strpos(strtolower($sql), 'order by "ingredient_id" asc') !== false
        );

        $this->assertNotNull($balanceLockQuery, 'Expected ingredient_balances query ordered by ingredient_id ASC.');
    }
}
