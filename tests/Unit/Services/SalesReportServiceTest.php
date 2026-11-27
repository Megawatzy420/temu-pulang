<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Ingredient;
use App\Models\IngredientBalance;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\ModifierRecipeItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\RecipeItem;
use App\Models\ShopSetting;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\DashboardMetricsService;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\StockAdjustmentService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\RefundService;
use App\Services\SalesReportService;
use App\Services\ShiftService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    private ShiftService $shiftService;

    private OrderService $orderService;

    private PaymentService $paymentService;

    private RefundService $refundService;

    private InventoryService $inventoryService;

    private StockAdjustmentService $stockAdjustmentService;

    private DashboardMetricsService $dashboardMetricsService;

    private SalesReportService $salesReportService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->owner = User::where('email', 'owner@temupulang.local')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();

        $this->shiftService = app(ShiftService::class);
        $this->orderService = app(OrderService::class);
        $this->paymentService = app(PaymentService::class);
        $this->refundService = app(RefundService::class);
        $this->inventoryService = app(InventoryService::class);
        $this->stockAdjustmentService = app(StockAdjustmentService::class);
        $this->dashboardMetricsService = app(DashboardMetricsService::class);
        $this->salesReportService = app(SalesReportService::class);
    }

    public function test_single_day_consistency_with_dashboard_metrics_service(): void
    {
        ShopSetting::current()?->update([
            'tax_rate' => 1000,
            'service_charge_rate' => 500,
            'business_day_start_time' => '06:00:00',
        ]);

        $fixedNow = CarbonImmutable::parse('2026-11-27 14:00:00', 'Asia/Jakarta');
        CarbonImmutable::setTestNow($fixedNow);

        $shift = $this->shiftService->openShift($this->cashier, 200000);
        $product = Product::where('has_variants', false)->where('is_sold_out', false)->firstOrFail();

        // 1. Cash order with partial refund
        $order1 = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);
        $tx1 = $this->paymentService->settlePayment($this->cashier, $shift, $order1, [[
            'payment_method' => 'cash',
            'amount' => $order1->total_amount,
            'tendered_amount' => $order1->total_amount + 10000,
            'change_amount' => 10000,
        ]]);
        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order1->fresh(),
            allocations: [['payment_id' => $tx1->payments->firstOrFail()->id, 'amount' => 5000, 'refund_method' => 'cash']],
            reason: 'Partial refund for consistency test',
            shift: $shift
        );

        // 2. QRIS + Debit split order
        $order2 = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_TAKEAWAY,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ]);
        $qrisPart = 20000;
        $debitPart = $order2->total_amount - $qrisPart;
        $qrisConf = $this->paymentService->createQrisConfirmation($this->cashier, $order2, $qrisPart, 'QRIS-CONSIST');
        $this->paymentService->settlePayment($this->cashier, $shift, $order2, [
            ['payment_method' => 'qris', 'amount' => $qrisPart, 'qris_confirmation_id' => $qrisConf->id],
            ['payment_method' => 'debit', 'amount' => $debitPart, 'reference_number' => 'EDC-CONSIST'],
        ]);

        // 3. Unpaid order (must be excluded by both services)
        $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $dashboard = $this->dashboardMetricsService->getDashboardSnapshot($fixedNow);
        $report = $this->salesReportService->buildReport('2026-11-27', '2026-11-27', $fixedNow);

        $this->assertSame($dashboard['gross_sales'], $report['totals']['gross_sales']);
        $this->assertSame($dashboard['refunds'], $report['totals']['refunds']);
        $this->assertSame($dashboard['net_sales'], $report['totals']['net_sales']);
        $this->assertSame($dashboard['paid_orders'], $report['totals']['paid_orders_count']);
        $this->assertSame($dashboard['avg_ticket'], $report['totals']['avg_ticket']);
        $this->assertSame($dashboard['payment_mix'], $report['payment_mix']);
        $this->assertCount(1, $report['daily_rows']);
        $this->assertSame('2026-11-27', $report['daily_rows'][0]['business_date']);

        CarbonImmutable::setTestNow();
    }

    public function test_multi_day_range_sums_to_totals_and_includes_zero_activity_days(): void
    {
        $shift = $this->shiftService->openShift($this->cashier, 100000);
        $product = Product::where('has_variants', false)->where('is_sold_out', false)->firstOrFail();

        // Day 1: 2026-11-20 at 10:00 WIB
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 10:00:00', 'Asia/Jakarta'));
        $o1 = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);
        $this->paymentService->settlePayment($this->cashier, $shift, $o1, [[
            'payment_method' => 'cash',
            'amount' => $o1->total_amount,
            'tendered_amount' => $o1->total_amount,
            'change_amount' => 0,
        ]]);

        // Day 2 (2026-11-21): zero activity

        // Day 3: 2026-11-22 at 15:00 WIB
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 15:00:00', 'Asia/Jakarta'));
        $o3 = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_TAKEAWAY,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $tx3 = $this->paymentService->settlePayment($this->cashier, $shift, $o3, [[
            'payment_method' => 'cash',
            'amount' => $o3->total_amount,
            'tendered_amount' => $o3->total_amount,
            'change_amount' => 0,
        ]]);
        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $o3->fresh(),
            allocations: [['payment_id' => $tx3->payments->firstOrFail()->id, 'amount' => 5000, 'refund_method' => 'cash']],
            reason: 'Day 3 partial refund',
            shift: $shift
        );

        $report = $this->salesReportService->buildReport('2026-11-20', '2026-11-22');

        $this->assertSame(3, $report['days_count']);
        $this->assertCount(3, $report['daily_rows']);

        $this->assertSame('2026-11-20', $report['daily_rows'][0]['business_date']);
        $this->assertSame(1, $report['daily_rows'][0]['paid_orders_count']);
        $this->assertSame($o1->total_amount, $report['daily_rows'][0]['gross_sales']);

        // Day 2 has zero activity
        $this->assertSame('2026-11-21', $report['daily_rows'][1]['business_date']);
        $this->assertSame(0, $report['daily_rows'][1]['paid_orders_count']);
        $this->assertSame(0, $report['daily_rows'][1]['gross_sales']);
        $this->assertSame(0, $report['daily_rows'][1]['net_sales']);
        $this->assertSame(0, $report['daily_rows'][1]['cogs']);
        $this->assertSame('—', $report['daily_rows'][1]['margin']['formatted']);

        // Day 3 has 1 order and 5,000 refund
        $this->assertSame('2026-11-22', $report['daily_rows'][2]['business_date']);
        $this->assertSame(1, $report['daily_rows'][2]['paid_orders_count']);
        $this->assertSame($o3->total_amount, $report['daily_rows'][2]['gross_sales']);
        $this->assertSame(5000, $report['daily_rows'][2]['refunds']);

        // Sum of daily rows matches totals
        $sumOrders = array_sum(array_column($report['daily_rows'], 'paid_orders_count'));
        $sumGross = array_sum(array_column($report['daily_rows'], 'gross_sales'));
        $sumRefunds = array_sum(array_column($report['daily_rows'], 'refunds'));
        $sumNet = array_sum(array_column($report['daily_rows'], 'net_sales'));

        $this->assertSame($sumOrders, $report['totals']['paid_orders_count']);
        $this->assertSame($sumGross, $report['totals']['gross_sales']);
        $this->assertSame($sumRefunds, $report['totals']['refunds']);
        $this->assertSame($sumNet, $report['totals']['net_sales']);

        CarbonImmutable::setTestNow();
    }

    public function test_business_day_boundary_at_0200_and_cross_day_refund_attribution(): void
    {
        ShopSetting::current()?->update([
            'business_day_start_time' => '06:00:00',
        ]);

        $shift = $this->shiftService->openShift($this->cashier, 100000);
        $product = Product::where('has_variants', false)->where('is_sold_out', false)->firstOrFail();

        // Order paid at 02:00 on 2026-11-25 belongs to business day 2026-11-24 (since cutoff is 06:00)
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-25 02:00:00', 'Asia/Jakarta'));
        $lateNightOrder = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $tx = $this->paymentService->settlePayment($this->cashier, $shift, $lateNightOrder, [[
            'payment_method' => 'cash',
            'amount' => $lateNightOrder->total_amount,
            'tendered_amount' => $lateNightOrder->total_amount,
            'change_amount' => 0,
        ]]);

        // Refund processed at 11:00 on 2026-11-25 belongs to business day 2026-11-25
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-25 11:00:00', 'Asia/Jakarta'));
        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $lateNightOrder->fresh(),
            allocations: [['payment_id' => $tx->payments->firstOrFail()->id, 'amount' => 10000, 'refund_method' => 'cash']],
            reason: 'Cross-day refund',
            shift: $shift
        );

        $report = $this->salesReportService->buildReport('2026-11-24', '2026-11-25');

        $day24 = $report['daily_rows'][0];
        $day25 = $report['daily_rows'][1];

        $this->assertSame('2026-11-24', $day24['business_date']);
        $this->assertSame($lateNightOrder->total_amount, $day24['gross_sales']);
        $this->assertSame(0, $day24['refunds']);
        $this->assertSame($lateNightOrder->total_amount, $day24['net_sales']);

        $this->assertSame('2026-11-25', $day25['business_date']);
        $this->assertSame(0, $day25['gross_sales']);
        $this->assertSame(10000, $day25['refunds']);
        $this->assertSame(-10000, $day25['net_sales']);
        $this->assertSame('—', $day25['margin']['formatted']);

        CarbonImmutable::setTestNow();
    }

    public function test_cogs_full_refund_restock_partial_refund_decimal_exactness_snapshot_immutability_and_tracking_start_warning(): void
    {
        // Clear any existing seeded orders/stock_movements to test clean COGS math
        StockMovement::query()->delete();

        $shift = $this->shiftService->openShift($this->cashier, 200000);

        // Create a dedicated product and ingredient with exact decimal quantity 0.025 and unit_cost 1500
        // 0.025 * 1500 = 37.5 Rupiah (37,500 milli-Rupiah)
        $ingredient = Ingredient::create([
            'sku' => 'ING-TEST-025',
            'name' => 'Bubuk Teh Uji',
            'unit' => 'kg',
            'cost_per_unit' => 1500,
            'min_stock_alert' => '0.000',
            'is_active' => true,
        ]);
        IngredientBalance::create([
            'branch_id' => null,
            'ingredient_id' => $ingredient->id,
            'current_stock' => '10.000',
        ]);

        $category = Product::firstOrFail()->category;
        $costedProduct = Product::create([
            'category_id' => $category->id,
            'sku' => 'PRD-COGS-025',
            'base_price' => 20000,
            'has_variants' => false,
            'is_active' => true,
            'is_sold_out' => false,
            'is_dine_in_available' => true,
            'is_takeaway_available' => true,
        ]);
        ProductTranslation::create(['product_id' => $costedProduct->id, 'locale' => 'id', 'name' => 'Teh Uji COGS']);
        ProductTranslation::create(['product_id' => $costedProduct->id, 'locale' => 'en', 'name' => 'COGS Test Tea']);

        // 1. First, pay an order on 2026-11-10 BEFORE any recipe or order_deduction movement exists
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 10:00:00', 'Asia/Jakarta'));
        $preTrackingOrder = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $costedProduct->id, 'quantity' => 1]],
        ]);
        $this->paymentService->settlePayment($this->cashier, $shift, $preTrackingOrder, [[
            'payment_method' => 'cash',
            'amount' => $preTrackingOrder->total_amount,
            'tendered_amount' => $preTrackingOrder->total_amount,
            'change_amount' => 0,
        ]]);

        // Report on 2026-11-10 before any deduction exists: COGS is 0 and warning is shown
        $preReport = $this->salesReportService->buildReport('2026-11-10', '2026-11-10');
        $this->assertSame(0, $preReport['totals']['cogs']);
        $this->assertNull($preReport['cogs_tracking']['earliest_deduction_date']);
        $this->assertTrue($preReport['cogs_tracking']['show_warning']);

        // Now attach recipe: 0.025 kg per item at 1500/kg = 37,500 milli-Rupiah (37.5 Rp)
        RecipeItem::create([
            'product_id' => $costedProduct->id,
            'product_variant_id' => null,
            'ingredient_id' => $ingredient->id,
            'quantity' => '0.025',
        ]);

        // 2. Day 2026-11-15: Pay order A (1 item -> 37,500 milli-Rp -> rounds half-up to 38 Rp)
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-15 10:00:00', 'Asia/Jakarta'));
        $orderA = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $costedProduct->id, 'quantity' => 1]],
        ]);
        $this->paymentService->settlePayment($this->cashier, $shift, $orderA, [[
            'payment_method' => 'cash',
            'amount' => $orderA->total_amount,
            'tendered_amount' => $orderA->total_amount,
            'change_amount' => 0,
        ]]);

        // 3. Day 2026-11-16: Pay order B (1 item -> 37,500 milli-Rp -> rounds half-up to 38 Rp for the day)
        // Over [2026-11-15, 2026-11-16]:
        // Day 15 rounded COGS = 38, Day 16 rounded COGS = 38 -> sum of daily rounded COGS = 76
        // Total milli-Rp = 37,500 + 37,500 = 75,000 -> total COGS = 75 Rp!
        // Rounding note must be triggered (diff = -1).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16 10:00:00', 'Asia/Jakarta'));
        $orderB = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $costedProduct->id, 'quantity' => 1]],
        ]);
        $this->paymentService->settlePayment($this->cashier, $shift, $orderB, [[
            'payment_method' => 'cash',
            'amount' => $orderB->total_amount,
            'tendered_amount' => $orderB->total_amount,
            'change_amount' => 0,
        ]]);

        // 4. Change ingredient.cost_per_unit later -> historical COGS must NOT change because stock_movements.unit_cost is snapshotted
        $ingredient->update(['cost_per_unit' => 99000]);

        $twoDayReport = $this->salesReportService->buildReport('2026-11-15', '2026-11-16');
        $this->assertSame(38, $twoDayReport['daily_rows'][0]['cogs']);
        $this->assertSame(38, $twoDayReport['daily_rows'][1]['cogs']);
        $this->assertSame(76, $twoDayReport['totals']['daily_rounded_cogs_sum']);
        $this->assertSame(75, $twoDayReport['totals']['cogs']);
        $this->assertSame(-1, $twoDayReport['totals']['cogs_rounding_diff']);
        $this->assertTrue($twoDayReport['totals']['has_cogs_rounding_note']);
        $this->assertSame('2026-11-15', $twoDayReport['cogs_tracking']['earliest_deduction_date']);
        $this->assertFalse($twoDayReport['cogs_tracking']['show_warning']);

        // And a report starting on 2026-11-10 (before earliest deduction date 2026-11-15) triggers the warning
        $spanReport = $this->salesReportService->buildReport('2026-11-10', '2026-11-16');
        $this->assertTrue($spanReport['cogs_tracking']['show_warning']);

        // 5. Partial refund on 2026-11-17 does NOT create refund_credit and does NOT reduce COGS
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-17 10:00:00', 'Asia/Jakarta'));
        $orderC = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $costedProduct->id, 'quantity' => 2]],
        ]);
        $txC = $this->paymentService->settlePayment($this->cashier, $shift, $orderC, [[
            'payment_method' => 'cash',
            'amount' => $orderC->total_amount,
            'tendered_amount' => $orderC->total_amount,
            'change_amount' => 0,
        ]]);
        // Note: on 2026-11-17 ingredient cost_per_unit is 99000, so 2 * 0.025 * 99000 = 4950 Rp
        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $orderC->fresh(),
            allocations: [['payment_id' => $txC->payments->firstOrFail()->id, 'amount' => 5000, 'refund_method' => 'cash']],
            reason: 'Partial refund does not restock',
            shift: $shift
        );

        $day17Report = $this->salesReportService->buildReport('2026-11-17', '2026-11-17');
        $this->assertSame(4950, $day17Report['totals']['cogs']);

        // 6. Full refund with restockForRefund on 2026-11-18 reduces COGS via refund_credit
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-18 10:00:00', 'Asia/Jakarta'));
        $orderD = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $costedProduct->id, 'quantity' => 2]],
        ]);
        $txD = $this->paymentService->settlePayment($this->cashier, $shift, $orderD, [[
            'payment_method' => 'cash',
            'amount' => $orderD->total_amount,
            'tendered_amount' => $orderD->total_amount,
            'change_amount' => 0,
        ]]);
        $refundTxD = $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $orderD->fresh(),
            allocations: [['payment_id' => $txD->payments->firstOrFail()->id, 'amount' => $orderD->total_amount, 'refund_method' => 'cash']],
            reason: 'Full refund with restock',
            shift: $shift
        );
        $this->inventoryService->restockForRefund($refundTxD, true, $this->owner);

        $day18Report = $this->salesReportService->buildReport('2026-11-18', '2026-11-18');
        $this->assertSame(0, $day18Report['totals']['cogs']);
        $this->assertSame(0, $day18Report['totals']['net_sales']);
        $this->assertSame('—', $day18Report['totals']['margin']['formatted']);

        CarbonImmutable::setTestNow();
    }

    public function test_gross_profit_and_margin_half_up_integer_math_and_dash_when_net_sales_non_positive(): void
    {
        // 1. net_sales <= 0 -> margin is '—'
        $this->assertSame(['tenths' => null, 'formatted' => '—'], SalesReportService::marginInTenths(0, 0));
        $this->assertSame(['tenths' => null, 'formatted' => '—'], SalesReportService::marginInTenths(-5000, -5000));
        $this->assertSame(['tenths' => null, 'formatted' => '—'], SalesReportService::marginInTenths(1000, 0));

        // 2. gross_profit === 0 with positive net_sales -> '0.0%'
        $this->assertSame(['tenths' => 0, 'formatted' => '0.0%'], SalesReportService::marginInTenths(0, 100000));

        // 3. Positive margin with half-up rounding: 66665 / 100000 = 66.665% -> 66.7% (667 tenths)
        $this->assertSame(['tenths' => 667, 'formatted' => '66.7%'], SalesReportService::marginInTenths(66665, 100000));

        // 4. Positive margin rounding down: 66640 / 100000 = 66.64% -> 66.6% (666 tenths)
        $this->assertSame(['tenths' => 666, 'formatted' => '66.6%'], SalesReportService::marginInTenths(66640, 100000));

        // 5. Negative gross profit when COGS > net_sales: -25000 / 100000 = -25.0%
        $this->assertSame(['tenths' => -250, 'formatted' => '-25.0%'], SalesReportService::marginInTenths(-25000, 100000));
    }

    public function test_uncosted_sales_includes_products_without_recipes_and_excludes_base_or_modifier_recipe_products(): void
    {
        $shift = $this->shiftService->openShift($this->cashier, 100000);
        $category = Product::firstOrFail()->category;
        $ingredient = Ingredient::create([
            'sku' => 'ING-UNCOSTED-TEST',
            'name' => 'Bahan Uji Resep',
            'unit' => 'kg',
            'cost_per_unit' => 2000,
            'min_stock_alert' => '0.000',
            'is_active' => true,
        ]);

        // Product 1: No recipe at all (uncosted)
        $uncostedProduct = Product::create([
            'category_id' => $category->id,
            'sku' => 'PRD-UNCOSTED',
            'base_price' => 10000,
            'has_variants' => false,
            'is_active' => true,
            'is_sold_out' => false,
            'is_dine_in_available' => true,
            'is_takeaway_available' => true,
        ]);
        ProductTranslation::create(['product_id' => $uncostedProduct->id, 'locale' => 'id', 'name' => 'Air Mineral Botol']);
        ProductTranslation::create(['product_id' => $uncostedProduct->id, 'locale' => 'en', 'name' => 'Bottled Mineral Water']);

        // Product 2: Has base RecipeItem (costed)
        $baseCostedProduct = Product::create([
            'category_id' => $category->id,
            'sku' => 'PRD-BASE-COSTED',
            'base_price' => 20000,
            'has_variants' => false,
            'is_active' => true,
            'is_sold_out' => false,
            'is_dine_in_available' => true,
            'is_takeaway_available' => true,
        ]);
        ProductTranslation::create(['product_id' => $baseCostedProduct->id, 'locale' => 'id', 'name' => 'Kopi Hitam Resep']);
        ProductTranslation::create(['product_id' => $baseCostedProduct->id, 'locale' => 'en', 'name' => 'Recipe Black Coffee']);
        RecipeItem::create([
            'product_id' => $baseCostedProduct->id,
            'product_variant_id' => null,
            'ingredient_id' => $ingredient->id,
            'quantity' => '0.010',
        ]);

        // Product 3: No base RecipeItem, but linked to a ModifierGroup whose modifier has a ModifierRecipeItem (costed)
        $modCostedProduct = Product::create([
            'category_id' => $category->id,
            'sku' => 'PRD-MOD-COSTED',
            'base_price' => 30000,
            'has_variants' => false,
            'is_active' => true,
            'is_sold_out' => false,
            'is_dine_in_available' => true,
            'is_takeaway_available' => true,
        ]);
        ProductTranslation::create(['product_id' => $modCostedProduct->id, 'locale' => 'id', 'name' => 'Minuman Custom Modifier']);
        ProductTranslation::create(['product_id' => $modCostedProduct->id, 'locale' => 'en', 'name' => 'Custom Modifier Drink']);
        $group = ModifierGroup::create([
            'name_id' => 'Grup Sirup Uji',
            'name_en' => 'Test Syrup Group',
            'slug' => 'grup-sirup-uji-72',
            'is_required' => false,
            'min_select' => 0,
            'max_select' => 1,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $modifier = Modifier::create([
            'modifier_group_id' => $group->id,
            'name_id' => 'Sirup Vanila',
            'name_en' => 'Vanilla Syrup',
            'price' => 0,
            'sort_order' => 1,
            'is_active' => true,
            'is_sold_out' => false,
        ]);
        ModifierRecipeItem::create([
            'modifier_id' => $modifier->id,
            'ingredient_id' => $ingredient->id,
            'action' => 'add',
            'quantity' => '0.015',
        ]);
        $modCostedProduct->modifierGroups()->attach($group->id, ['sort_order' => 1]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 12:00:00', 'Asia/Jakarta'));

        $order = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $uncostedProduct->id, 'quantity' => 2], // 20,000 uncosted
                ['product_id' => $baseCostedProduct->id, 'quantity' => 1], // 20,000 costed
                ['product_id' => $modCostedProduct->id, 'quantity' => 1], // 30,000 costed via modifier recipe
            ],
        ]);
        $this->paymentService->settlePayment($this->cashier, $shift, $order, [[
            'payment_method' => 'cash',
            'amount' => $order->total_amount,
            'tendered_amount' => $order->total_amount,
            'change_amount' => 0,
        ]]);

        $report = $this->salesReportService->buildReport('2026-11-23', '2026-11-23');

        // Total gross sales = 70,000 (when tax/service = 0); uncosted = 20,000
        // 20,000 / 70,000 = 28.571% -> 28.6% (286 tenths)
        $this->assertSame(70000, $report['totals']['gross_sales']);
        $this->assertSame(20000, $report['uncosted_sales']['amount']);
        $this->assertSame(286, $report['uncosted_sales']['share_tenths']);
        $this->assertSame('28.6%', $report['uncosted_sales']['share_formatted']);

        CarbonImmutable::setTestNow();
    }

    public function test_query_count_stays_constant_between_7_day_and_90_day_ranges(): void
    {
        $setting = ShopSetting::current();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $report7 = $this->salesReportService->buildReport('2026-11-01', '2026-11-07', null, $setting);
        $queries7 = count(DB::getQueryLog());

        DB::flushQueryLog();
        $report90 = $this->salesReportService->buildReport('2026-09-01', '2026-11-29', null, $setting);
        $queries90 = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(7, $report7['daily_rows']);
        $this->assertCount(90, $report90['daily_rows']);
        $this->assertLessThanOrEqual(8, $queries7);
        $this->assertSame($queries7, $queries90);
    }
}
