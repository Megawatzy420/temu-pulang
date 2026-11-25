<?php

namespace Tests\Feature\Inventory;

use App\Livewire\Orders\OrderRefund;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientBalance;
use App\Models\IngredientCategory;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\ModifierGroupTranslation;
use App\Models\ModifierRecipeItem;
use App\Models\ModifierTranslation;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\RecipeItem;
use App\Models\RefundTransaction;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ShiftService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class RefundRestockTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $cashier;

    protected Shift $shift;

    protected Ingredient $beans;

    protected Ingredient $milk;

    protected Product $latte;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->owner = User::where('email', 'owner@temupulang.local')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();
        $this->shift = app(ShiftService::class)->openShift($this->cashier, 200000, 'Restock test shift');

        $ingCat = IngredientCategory::create(['name' => 'Bar', 'slug' => 'bar-restock', 'sort_order' => 1]);
        $this->beans = Ingredient::create(['category_id' => $ingCat->id, 'sku' => 'RS-BEANS', 'name' => 'Beans', 'unit' => 'kg', 'cost_per_unit' => 200000, 'min_stock_alert' => '1.000', 'is_active' => true]);
        $this->milk = Ingredient::create(['category_id' => $ingCat->id, 'sku' => 'RS-MILK', 'name' => 'Milk', 'unit' => 'L', 'cost_per_unit' => 25000, 'min_stock_alert' => '1.000', 'is_active' => true]);

        IngredientBalance::create(['branch_id' => null, 'ingredient_id' => $this->beans->id, 'current_stock' => '5.000']);
        IngredientBalance::create(['branch_id' => null, 'ingredient_id' => $this->milk->id, 'current_stock' => '10.000']);

        $cat = Category::firstOrFail();
        $this->latte = Product::create(['category_id' => $cat->id, 'sku' => 'RS-LATTE', 'base_price' => 30000, 'has_variants' => false, 'is_active' => true]);
        ProductTranslation::create(['product_id' => $this->latte->id, 'locale' => 'id', 'name' => 'Latte']);
        ProductTranslation::create(['product_id' => $this->latte->id, 'locale' => 'en', 'name' => 'Latte']);

        RecipeItem::create(['product_id' => $this->latte->id, 'product_variant_id' => null, 'ingredient_id' => $this->beans->id, 'quantity' => '0.025']);
        RecipeItem::create(['product_id' => $this->latte->id, 'product_variant_id' => null, 'ingredient_id' => $this->milk->id, 'quantity' => '0.200']);
    }

    protected function createSettledOrder(int $quantity = 1, array $modifierIds = []): array
    {
        $order = app(OrderService::class)->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [[
                'product_id' => $this->latte->id,
                'quantity' => $quantity,
                'modifier_ids' => $modifierIds,
            ]],
        ]);

        app(PaymentService::class)->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order,
            tenders: [['payment_method' => 'cash', 'amount' => $order->total_amount, 'tendered_amount' => $order->total_amount, 'change_amount' => 0]],
            idempotencyKey: 'pay_'.Str::uuid()
        );

        $order->refresh();

        return [$order, $order->payments()->firstOrFail()];
    }

    public function test_checkbox_defaults_by_order_status_and_visibility_for_partial_vs_no_deduction(): void
    {
        [$order, $payment] = $this->createSettledOrder(1);

        // paid -> checked by default; hidden while amount is 0 (partial), visible when full
        $compPaid = Livewire::actingAs($this->owner)
            ->test(OrderRefund::class, ['order' => $order])
            ->assertSet('returnToStock', true)
            ->assertDontSee(__('messages.refund.restock_checkbox'))
            ->set("allocations.{$payment->id}.amount", $payment->amount)
            ->assertSee(__('messages.refund.restock_checkbox'));

        // Toggle works
        $compPaid->set('returnToStock', false)->assertSet('returnToStock', false);

        // queued -> checked
        DB::table('orders')->where('id', $order->id)->update(['status' => Order::STATUS_QUEUED]);
        Livewire::actingAs($this->owner)
            ->test(OrderRefund::class, ['order' => $order->fresh()])
            ->assertSet('returnToStock', true);

        // preparing, ready, completed -> unchecked
        foreach ([Order::STATUS_PREPARING, Order::STATUS_READY, Order::STATUS_COMPLETED] as $status) {
            DB::table('orders')->where('id', $order->id)->update(['status' => $status]);
            Livewire::actingAs($this->owner)
                ->test(OrderRefund::class, ['order' => $order->fresh()])
                ->assertSet('returnToStock', false);
        }

        // Order without deduction movements -> hidden even at full refund
        $noRecProduct = Product::create(['category_id' => $this->latte->category_id, 'sku' => 'RS-WATER', 'base_price' => 10000, 'has_variants' => false, 'is_active' => true]);
        ProductTranslation::create(['product_id' => $noRecProduct->id, 'locale' => 'id', 'name' => 'Water']);
        ProductTranslation::create(['product_id' => $noRecProduct->id, 'locale' => 'en', 'name' => 'Water']);
        $noRecOrder = app(OrderService::class)->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_TAKEAWAY,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $noRecProduct->id, 'quantity' => 1]],
        ]);
        app(PaymentService::class)->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $noRecOrder,
            tenders: [['payment_method' => 'cash', 'amount' => $noRecOrder->total_amount, 'tendered_amount' => $noRecOrder->total_amount, 'change_amount' => 0]],
            idempotencyKey: 'pay_'.Str::uuid()
        );
        $noRecPayment = $noRecOrder->payments()->firstOrFail();

        Livewire::actingAs($this->owner)
            ->test(OrderRefund::class, ['order' => $noRecOrder->fresh()])
            ->set("allocations.{$noRecPayment->id}.amount", $noRecPayment->amount)
            ->assertDontSee(__('messages.refund.restock_checkbox'));
    }

    public function test_full_refund_credits_exact_original_deduction_even_after_recipe_edit(): void
    {
        $group = ModifierGroup::create(['slug' => 'rs-extra', 'is_required' => false, 'min_select' => 0, 'max_select' => 2, 'is_active' => true]);
        ModifierGroupTranslation::create(['modifier_group_id' => $group->id, 'locale' => 'id', 'name' => 'Tambahan']);
        ModifierGroupTranslation::create(['modifier_group_id' => $group->id, 'locale' => 'en', 'name' => 'Add-ons']);
        $extraShot = Modifier::create(['modifier_group_id' => $group->id, 'price' => 5000, 'is_active' => true]);
        ModifierTranslation::create(['modifier_id' => $extraShot->id, 'locale' => 'id', 'name' => 'Ekstra Shot']);
        ModifierTranslation::create(['modifier_id' => $extraShot->id, 'locale' => 'en', 'name' => 'Extra Shot']);
        ModifierRecipeItem::create(['modifier_id' => $extraShot->id, 'ingredient_id' => $this->beans->id, 'action' => ModifierRecipeItem::ACTION_ADD, 'quantity' => '0.025']);

        // 7 lattes with extra shot: beans (0.025 + 0.025) * 7 = 0.350, milk 0.200 * 7 = 1.400
        [$order, $payment] = $this->createSettledOrder(7, [$extraShot->id]);

        $this->assertSame(4650, IngredientBalance::where('ingredient_id', $this->beans->id)->firstOrFail()->current_stock_milli);
        $this->assertSame(8600, IngredientBalance::where('ingredient_id', $this->milk->id)->firstOrFail()->current_stock_milli);

        // Edit recipe AFTER sale — restock must still credit original deducted amounts!
        RecipeItem::where('product_id', $this->latte->id)->where('ingredient_id', $this->beans->id)->update(['quantity' => '0.999']);

        $component = Livewire::actingAs($this->owner)
            ->test(OrderRefund::class, ['order' => $order])
            ->set("allocations.{$payment->id}.amount", $payment->amount)
            ->set("allocations.{$payment->id}.refund_method", 'cash')
            ->set('reason', 'Full order return')
            ->set('returnToStock', true)
            ->call('submitRefund')
            ->assertSee(__('messages.refund.restock_credited'));

        $this->assertSame(5000, IngredientBalance::where('ingredient_id', $this->beans->id)->firstOrFail()->current_stock_milli);
        $this->assertSame(10000, IngredientBalance::where('ingredient_id', $this->milk->id)->firstOrFail()->current_stock_milli);

        $refundTx = RefundTransaction::where('order_id', $order->id)->firstOrFail();
        $beansCredit = StockMovement::where('movement_type', StockMovement::TYPE_REFUND_CREDIT)
            ->where('ingredient_id', $this->beans->id)
            ->firstOrFail();
        $this->assertSame(350, $beansCredit->quantity_milli);
        $this->assertSame(200000, $beansCredit->unit_cost);
        $this->assertSame($this->owner->id, $beansCredit->user_id);
        $this->assertSame('orders', $beansCredit->reference_type);
        $this->assertSame($order->id, $beansCredit->reference_id);
        $this->assertSame($refundTx->ulid, $beansCredit->idempotency_key);

        // Replay same refund form key & prevent over-credit
        $component->call('submitRefund');
        $this->assertSame(1, StockMovement::where('movement_type', StockMovement::TYPE_REFUND_CREDIT)->where('ingredient_id', $this->beans->id)->count());

        $result = app(InventoryService::class)->restockForRefund($refundTx, true, $this->owner);
        $this->assertSame('credited', $result);
        $this->assertSame(5000, IngredientBalance::where('ingredient_id', $this->beans->id)->firstOrFail()->current_stock_milli);

        // Append-only enforced
        $this->assertThrows(fn () => $beansCredit->update(['notes' => 'tamper']), DomainException::class);
        $this->assertThrows(fn () => $beansCredit->delete(), DomainException::class);
    }

    public function test_unchecked_partial_and_two_step_full_refund_rules(): void
    {
        // Unchecked -> no movements
        [$order1, $pay1] = $this->createSettledOrder(1);
        Livewire::actingAs($this->owner)
            ->test(OrderRefund::class, ['order' => $order1])
            ->set("allocations.{$pay1->id}.amount", $pay1->amount)
            ->set('reason', 'Unchecked')
            ->set('returnToStock', false)
            ->call('submitRefund');
        $this->assertSame(0, StockMovement::where('movement_type', StockMovement::TYPE_REFUND_CREDIT)->count());

        // Two-step full refund: partial -> no credit; final -> credits entire original deduction once
        [$order2, $pay2] = $this->createSettledOrder(2);
        $half = intdiv($pay2->amount, 2);
        $rest = $pay2->amount - $half;

        Livewire::actingAs($this->owner)
            ->test(OrderRefund::class, ['order' => $order2])
            ->set("allocations.{$pay2->id}.amount", $half)
            ->set('reason', 'Step 1 partial')
            ->call('submitRefund');
        $this->assertSame(0, StockMovement::where('movement_type', StockMovement::TYPE_REFUND_CREDIT)->count());

        $beansBeforeFinal = IngredientBalance::where('ingredient_id', $this->beans->id)->firstOrFail()->current_stock_milli;

        Livewire::actingAs($this->owner)
            ->test(OrderRefund::class, ['order' => $order2->fresh()])
            ->set("allocations.{$pay2->id}.amount", $rest)
            ->set('reason', 'Step 2 final')
            ->set('returnToStock', true)
            ->call('submitRefund')
            ->assertSee(__('messages.refund.restock_credited'));

        // 2 lattes = 0.050 beans returned
        $this->assertSame($beansBeforeFinal + 50, IngredientBalance::where('ingredient_id', $this->beans->id)->firstOrFail()->current_stock_milli);
    }

    public function test_cancelling_unpaid_order_creates_no_stock_movements(): void
    {
        $unpaidOrder = app(OrderService::class)->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $this->latte->id, 'quantity' => 2]],
        ]);

        app(OrderService::class)->cancelOrder($unpaidOrder, $this->owner, 'Customer left before paying');

        $this->assertSame(Order::STATUS_CANCELLED, $unpaidOrder->fresh()->status);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_restock_failure_does_not_block_refund_and_retry_restocks_command_recovers(): void
    {
        [$order, $payment] = $this->createSettledOrder(1);

        // Unrelated refund without refund_restock_failed log
        [$otherOrder, $otherPayment] = $this->createSettledOrder(1);
        Livewire::actingAs($this->owner)
            ->test(OrderRefund::class, ['order' => $otherOrder])
            ->set("allocations.{$otherPayment->id}.amount", $otherPayment->amount)
            ->set('reason', 'Other refund')
            ->set('returnToStock', false)
            ->call('submitRefund');
        $otherRefund = RefundTransaction::where('order_id', $otherOrder->id)->firstOrFail();

        $this->mock(InventoryService::class, function ($mock) {
            $mock->shouldReceive('hasUncreditedDeductions')->andReturn(true);
            $mock->shouldReceive('restockForRefund')
                ->once()
                ->andThrow(new \RuntimeException('Simulated restock failure'));
        });

        Livewire::actingAs($this->owner)
            ->test(OrderRefund::class, ['order' => $order])
            ->set("allocations.{$payment->id}.amount", $payment->amount)
            ->set('reason', 'Refund with restock error')
            ->set('returnToStock', true)
            ->call('submitRefund')
            ->assertSet('restockStatus', 'failed')
            ->assertSee(__('messages.refund.restock_failed_warning'));

        $this->assertSame(Order::PAYMENT_STATUS_REFUNDED, $order->fresh()->payment_status);
        $refund = RefundTransaction::where('order_id', $order->id)->firstOrFail();

        $this->assertDatabaseHas('activity_logs', [
            'module' => 'inventory',
            'action' => 'refund_restock_failed',
            'subject_id' => $refund->id,
        ]);

        // Restore real InventoryService for retry command
        $this->app->forgetInstance(InventoryService::class);

        $this->artisan('inventory:retry-restocks')
            ->expectsOutputToContain($refund->refund_transaction_number)
            ->doesntExpectOutputToContain($otherRefund->refund_transaction_number)
            ->assertExitCode(0);
        $this->assertSame(0, StockMovement::where('movement_type', StockMovement::TYPE_REFUND_CREDIT)->count());

        $this->artisan('inventory:retry-restocks --apply')
            ->expectsOutputToContain($refund->refund_transaction_number)
            ->assertExitCode(0);
        $this->assertSame(2, StockMovement::where('movement_type', StockMovement::TYPE_REFUND_CREDIT)->count());

        // Second --apply does nothing
        $this->artisan('inventory:retry-restocks --apply')
            ->expectsOutputToContain('No pending failed restocks remain.')
            ->assertExitCode(0);
        $this->assertSame(2, StockMovement::where('movement_type', StockMovement::TYPE_REFUND_CREDIT)->count());
    }

    public function test_balances_locked_in_ascending_ingredient_id_order_rbac_and_locales(): void
    {
        [$order, $payment] = $this->createSettledOrder(1);

        DB::enableQueryLog();
        DB::flushQueryLog();

        Livewire::actingAs($this->owner)
            ->test(OrderRefund::class, ['order' => $order])
            ->set("allocations.{$payment->id}.amount", $payment->amount)
            ->set('reason', 'Lock order test')
            ->set('returnToStock', true)
            ->call('submitRefund');

        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $lockQuery = $queries->first(
            static fn (string $sql): bool => strpos($sql, 'ingredient_balances') !== false && strpos(strtolower($sql), 'order by "ingredient_id" asc') !== false
        );
        $this->assertNotNull($lockQuery);

        // Cashier blocked from refund screen
        Livewire::actingAs($this->cashier)
            ->test(OrderRefund::class, ['order' => $order])
            ->assertForbidden();

        // Both locales render new labels and lang keys match
        [$order2, $pay2] = $this->createSettledOrder(1);
        App::setLocale('id');
        Livewire::actingAs($this->owner)
            ->test(OrderRefund::class, ['order' => $order2])
            ->set("allocations.{$pay2->id}.amount", $pay2->amount)
            ->assertSee('Kembalikan bahan baku ke stok');

        App::setLocale('en');
        Livewire::actingAs($this->owner)
            ->test(OrderRefund::class, ['order' => $order2])
            ->set("allocations.{$pay2->id}.amount", $pay2->amount)
            ->assertSee('Return ingredients to stock');

        $idKeys = array_keys(Arr::dot(require base_path('lang/id/messages.php')));
        $enKeys = array_keys(Arr::dot(require base_path('lang/en/messages.php')));
        sort($idKeys);
        sort($enKeys);
        $this->assertSame($idKeys, $enKeys);
    }
}
