<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\ShopSetting;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\RefundService;
use App\Services\SalesReportService;
use App\Services\ShiftService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    private ShiftService $shiftService;

    private OrderService $orderService;

    private PaymentService $paymentService;

    private RefundService $refundService;

    private SalesReportService $salesReportService;

    private AnalyticsService $analyticsService;

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
        $this->salesReportService = app(SalesReportService::class);
        $this->analyticsService = app(AnalyticsService::class);
    }

    public function test_breakdown_totals_match_sales_report_gross_sales_and_paid_orders_count(): void
    {
        ShopSetting::current()?->update([
            'tax_rate' => 1000,
            'service_charge_rate' => 500,
            'business_day_start_time' => '06:00:00',
        ]);

        $shift = $this->shiftService->openShift($this->cashier, 200000);
        $product = Product::where('has_variants', false)->where('is_sold_out', false)->firstOrFail();

        // 1. Dine-in order on 2026-11-24 at 10:15 WIB (paid)
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-24 10:15:00', 'Asia/Jakarta'));
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

        // 2. Takeaway order on 2026-11-24 at 14:40 WIB (partially refunded)
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-24 14:40:00', 'Asia/Jakarta'));
        $o2 = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_TAKEAWAY,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ]);
        $tx2 = $this->paymentService->settlePayment($this->cashier, $shift, $o2, [[
            'payment_method' => 'cash',
            'amount' => $o2->total_amount,
            'tendered_amount' => $o2->total_amount,
            'change_amount' => 0,
        ]]);
        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $o2->fresh(),
            allocations: [['payment_id' => $tx2->payments->firstOrFail()->id, 'amount' => 5000, 'refund_method' => 'cash']],
            reason: 'Partial refund',
            shift: $shift
        );

        // 3. Dine-in order on 2026-11-25 at 01:30 WIB (belongs to business day 2026-11-24, fully refunded)
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-25 01:30:00', 'Asia/Jakarta'));
        $o3 = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
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
            allocations: [['payment_id' => $tx3->payments->firstOrFail()->id, 'amount' => $o3->total_amount, 'refund_method' => 'cash']],
            reason: 'Full refund',
            shift: $shift
        );

        // 4. Unpaid order (must be excluded from all breakdowns)
        $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ]);

        $salesReport = $this->salesReportService->buildReport('2026-11-24', '2026-11-25');
        $analytics = $this->analyticsService->buildAnalytics('2026-11-24', '2026-11-25');

        $expectedOrders = $salesReport['totals']['paid_orders_count'];
        $expectedGross = $salesReport['totals']['gross_sales'];

        $this->assertSame(3, $expectedOrders);
        $this->assertSame($expectedOrders, $analytics['totals']['paid_orders_count']);
        $this->assertSame($expectedGross, $analytics['totals']['gross_sales']);

        // Sum of hourly buckets == sales report totals
        $hourlyOrdersSum = array_sum(array_column($analytics['sales_by_hour']['hours'], 'orders_count'));
        $hourlySalesSum = array_sum(array_column($analytics['sales_by_hour']['hours'], 'gross_sales'));
        $this->assertSame($expectedOrders, $hourlyOrdersSum);
        $this->assertSame($expectedGross, $hourlySalesSum);
        $this->assertSame($expectedOrders, $analytics['sales_by_hour']['total_orders']);
        $this->assertSame($expectedGross, $analytics['sales_by_hour']['total_gross_sales']);

        // Sum of weekday buckets == sales report totals
        $weekdayOrdersSum = array_sum(array_column($analytics['sales_by_weekday']['weekdays'], 'orders_count'));
        $weekdaySalesSum = array_sum(array_column($analytics['sales_by_weekday']['weekdays'], 'gross_sales'));
        $this->assertSame($expectedOrders, $weekdayOrdersSum);
        $this->assertSame($expectedGross, $weekdaySalesSum);

        // Sum of order type buckets == sales report totals
        $typeOrdersSum = array_sum(array_column($analytics['sales_by_order_type']['types'], 'orders_count'));
        $typeSalesSum = array_sum(array_column($analytics['sales_by_order_type']['types'], 'gross_sales'));
        $this->assertSame($expectedOrders, $typeOrdersSum);
        $this->assertSame($expectedGross, $typeSalesSum);

        CarbonImmutable::setTestNow();
    }

    public function test_product_rankings_group_by_product_id_use_latest_snapshot_name_exclude_full_refunds_and_sort_ties(): void
    {
        App::setLocale('id');
        $shift = $this->shiftService->openShift($this->cashier, 200000);
        $category = Category::firstOrFail();

        // Create 3 products to test tie-breaking, latest snapshot name, and revenue vs quantity ranking
        $prodA = Product::create([
            'category_id' => $category->id,
            'sku' => 'SKU-A',
            'base_price' => 10000,
            'has_variants' => false,
            'is_active' => true,
        ]);
        ProductTranslation::create(['product_id' => $prodA->id, 'locale' => 'id', 'name' => 'Nama Lama A']);
        ProductTranslation::create(['product_id' => $prodA->id, 'locale' => 'en', 'name' => 'Old Name A']);

        $prodB = Product::create([
            'category_id' => $category->id,
            'sku' => 'SKU-B',
            'base_price' => 30000,
            'has_variants' => false,
            'is_active' => true,
        ]);
        ProductTranslation::create(['product_id' => $prodB->id, 'locale' => 'id', 'name' => 'Menu Mahal B']);
        ProductTranslation::create(['product_id' => $prodB->id, 'locale' => 'en', 'name' => 'Expensive Menu B']);

        $prodC = Product::create([
            'category_id' => $category->id,
            'sku' => 'SKU-C',
            'base_price' => 30000,
            'has_variants' => false,
            'is_active' => true,
        ]);
        ProductTranslation::create(['product_id' => $prodC->id, 'locale' => 'id', 'name' => 'Menu Awal C']);
        ProductTranslation::create(['product_id' => $prodC->id, 'locale' => 'en', 'name' => 'Alpha Menu C']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-26 10:00:00', 'Asia/Jakarta'));

        // Order 1: Prod A (qty 2 @ 10,000 = 20,000) under "Nama Lama A"
        $o1 = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $prodA->id, 'quantity' => 2]],
        ]);
        $this->paymentService->settlePayment($this->cashier, $shift, $o1, [[
            'payment_method' => 'cash',
            'amount' => $o1->total_amount,
            'tendered_amount' => $o1->total_amount,
            'change_amount' => 0,
        ]]);

        // Rename Prod A in catalog to "Nama Baru A" and sell 3 more -> total qty 5, total revenue 50,000, must display "Nama Baru A"
        ProductTranslation::where('product_id', $prodA->id)->where('locale', 'id')->update(['name' => 'Nama Baru A']);
        ProductTranslation::where('product_id', $prodA->id)->where('locale', 'en')->update(['name' => 'New Name A']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-26 11:00:00', 'Asia/Jakarta'));
        $o2 = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $prodA->id, 'quantity' => 3], // Prod A now 5 qty, 50,000 rev
                ['product_id' => $prodB->id, 'quantity' => 2], // Prod B: 2 qty, 60,000 rev
                ['product_id' => $prodC->id, 'quantity' => 2], // Prod C: 2 qty, 60,000 rev (tied with B on qty & rev; "Menu Awal C" < "Menu Mahal B" alphabetically)
            ],
        ]);
        $this->paymentService->settlePayment($this->cashier, $shift, $o2, [[
            'payment_method' => 'cash',
            'amount' => $o2->total_amount,
            'tendered_amount' => $o2->total_amount,
            'change_amount' => 0,
        ]]);

        // Order 3: Fully refunded order of Prod B (qty 10) -> must be EXCLUDED from product rankings!
        $oRefunded = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_TAKEAWAY,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $prodB->id, 'quantity' => 10]],
        ]);
        $txRef = $this->paymentService->settlePayment($this->cashier, $shift, $oRefunded, [[
            'payment_method' => 'cash',
            'amount' => $oRefunded->total_amount,
            'tendered_amount' => $oRefunded->total_amount,
            'change_amount' => 0,
        ]]);
        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $oRefunded->fresh(),
            allocations: [['payment_id' => $txRef->payments->firstOrFail()->id, 'amount' => $oRefunded->total_amount, 'refund_method' => 'cash']],
            reason: 'Full refund excluded from rankings',
            shift: $shift
        );

        // Also test null product_id fallback row on Order 2
        OrderItem::create([
            'order_id' => $o2->id,
            'product_id' => null,
            'product_variant_id' => null,
            'sku' => 'LEGACY-NULL-1',
            'product_name_id' => 'Item Kustom Lama',
            'product_name_en' => 'Legacy Custom Item',
            'unit_price' => 30000,
            'modifiers_unit_price' => 0,
            'final_unit_price' => 30000,
            'quantity' => 1,
            'subtotal' => 30000,
        ]);

        // Total ranked revenue: Prod A (50,000) + Prod B (60,000) + Prod C (60,000) + Legacy Null (30,000) = 200,000
        $analytics = $this->analyticsService->buildAnalytics('2026-11-26', '2026-11-26');

        $this->assertSame(200000, $analytics['totals']['ranked_items_revenue']);
        $this->assertSame(10, $analytics['totals']['ranked_items_quantity']);
        $this->assertSame(4, $analytics['totals']['ranked_products_count']);

        // Top by quantity:
        // #1: Prod A ("Nama Baru A", qty 5, rev 50,000, share 25.0%, bar 100%)
        // #2: Prod C ("Menu Awal C", qty 2, rev 60,000, share 30.0%, bar 40%) -> beats Prod B alphabetically on tie!
        // #3: Prod B ("Menu Mahal B", qty 2, rev 60,000, share 30.0%, bar 40%)
        // #4: Legacy Null ("Item Kustom Lama", qty 1, rev 30,000, share 15.0%, bar 20%)
        $topQty = $analytics['top_by_quantity'];
        $this->assertCount(4, $topQty);
        $this->assertSame('Nama Baru A', $topQty[0]['name']);
        $this->assertSame(5, $topQty[0]['quantity']);
        $this->assertSame(50000, $topQty[0]['revenue']);
        $this->assertSame('25.0%', $topQty[0]['revenue_share_formatted']);
        $this->assertSame(100, $topQty[0]['bar_pct']);

        $this->assertSame('Menu Awal C', $topQty[1]['name']);
        $this->assertSame('Menu Mahal B', $topQty[2]['name']);
        $this->assertSame('Item Kustom Lama', $topQty[3]['name']);
        $this->assertNull($topQty[3]['product_id']);

        // Top by revenue:
        // #1: Prod C (60,000, qty 2, share 30.0%, bar 100%)
        // #2: Prod B (60,000, qty 2, share 30.0%, bar 100%)
        // #3: Prod A (50,000, qty 5, share 25.0%, bar 83%)
        // #4: Legacy Null (30,000, qty 1, share 15.0%, bar 50%)
        $topRev = $analytics['top_by_revenue'];
        $this->assertSame('Menu Awal C', $topRev[0]['name']);
        $this->assertSame(60000, $topRev[0]['revenue']);
        $this->assertSame('30.0%', $topRev[0]['revenue_share_formatted']);
        $this->assertSame(100, $topRev[0]['bar_pct']);

        $this->assertSame('Menu Mahal B', $topRev[1]['name']);
        $this->assertSame('Nama Baru A', $topRev[2]['name']);
        $this->assertSame(83, $topRev[2]['bar_pct']);

        CarbonImmutable::setTestNow();
    }

    public function test_products_without_sales_filters_active_non_deleted_sorts_alphabetically_and_limits_to_20_with_remaining_count(): void
    {
        App::setLocale('id');
        // Deactivate all seeded products first so we have an exact controlled set
        Product::query()->update(['is_active' => false]);

        $category = Category::firstOrFail();
        $shift = $this->shiftService->openShift($this->cashier, 100000);

        // Create 1 active product that gets sold (must NOT be in products_without_sales)
        $soldProduct = Product::create([
            'category_id' => $category->id,
            'sku' => 'SOLD-01',
            'base_price' => 15000,
            'has_variants' => false,
            'is_active' => true,
        ]);
        ProductTranslation::create(['product_id' => $soldProduct->id, 'locale' => 'id', 'name' => 'Kopi Terjual']);

        // Create 1 inactive product without sales (must NOT be in products_without_sales)
        $inactiveProduct = Product::create([
            'category_id' => $category->id,
            'sku' => 'INACT-01',
            'base_price' => 15000,
            'has_variants' => false,
            'is_active' => false,
        ]);
        ProductTranslation::create(['product_id' => $inactiveProduct->id, 'locale' => 'id', 'name' => 'Kopi Nonaktif']);

        // Create 1 soft-deleted active product without sales (must NOT be in products_without_sales)
        $deletedProduct = Product::create([
            'category_id' => $category->id,
            'sku' => 'DEL-01',
            'base_price' => 15000,
            'has_variants' => false,
            'is_active' => true,
        ]);
        ProductTranslation::create(['product_id' => $deletedProduct->id, 'locale' => 'id', 'name' => 'Kopi Dihapus']);
        $deletedProduct->delete();

        // Create 23 active, non-deleted products without sales -> 20 shown + 3 remaining
        for ($i = 23; $i >= 1; $i--) {
            $num = sprintf('%02d', $i);
            $p = Product::create([
                'category_id' => $category->id,
                'sku' => "ZERO-{$num}",
                'base_price' => 12000,
                'has_variants' => false,
                'is_active' => true,
            ]);
            ProductTranslation::create([
                'product_id' => $p->id,
                'locale' => 'id',
                'name' => "Menu Tanpa Jual {$num}",
            ]);
        }

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-27 09:00:00', 'Asia/Jakarta'));
        $order = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $soldProduct->id, 'quantity' => 1]],
        ]);
        $this->paymentService->settlePayment($this->cashier, $shift, $order, [[
            'payment_method' => 'cash',
            'amount' => $order->total_amount,
            'tendered_amount' => $order->total_amount,
            'change_amount' => 0,
        ]]);

        $analytics = $this->analyticsService->buildAnalytics('2026-11-27', '2026-11-27');
        $zeroSales = $analytics['products_without_sales'];

        $this->assertSame(23, $zeroSales['total_count']);
        $this->assertSame(20, $zeroSales['shown_count']);
        $this->assertSame(3, $zeroSales['remaining_count']);
        $this->assertCount(20, $zeroSales['items']);

        // Verify alphabetical sorting: "Menu Tanpa Jual 01" first, "Menu Tanpa Jual 20" twentieth
        $this->assertSame('Menu Tanpa Jual 01', $zeroSales['items'][0]['name']);
        $this->assertSame('Menu Tanpa Jual 20', $zeroSales['items'][19]['name']);

        CarbonImmutable::setTestNow();
    }

    public function test_sales_by_hour_real_clock_hour_and_sales_by_business_weekday_after_midnight_and_order_types(): void
    {
        ShopSetting::current()?->update([
            'business_day_start_time' => '06:00:00',
        ]);

        $shift = $this->shiftService->openShift($this->cashier, 100000);
        $product = Product::where('has_variants', false)->where('is_sold_out', false)->firstOrFail();

        // 1. Monday 2026-11-23 at 14:20 WIB (Dine-In, 1 item)
        // Real clock hour = 14; Business date = 2026-11-23 (Monday, ISO weekday 1)
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 14:20:00', 'Asia/Jakarta'));
        $oMon = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $this->paymentService->settlePayment($this->cashier, $shift, $oMon, [[
            'payment_method' => 'cash',
            'amount' => $oMon->total_amount,
            'tendered_amount' => $oMon->total_amount,
            'change_amount' => 0,
        ]]);

        // 2. Tuesday calendar date 2026-11-24 at 01:30 WIB after midnight (Takeaway, 3 items)
        // Real clock hour = 1 (01:00); Business date = 2026-11-23 (Monday, ISO weekday 1) because 01:30 < 06:00 cutoff!
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-24 01:30:00', 'Asia/Jakarta'));
        $oAfterMidnight = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_TAKEAWAY,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ]);
        $this->paymentService->settlePayment($this->cashier, $shift, $oAfterMidnight, [[
            'payment_method' => 'cash',
            'amount' => $oAfterMidnight->total_amount,
            'tendered_amount' => $oAfterMidnight->total_amount,
            'change_amount' => 0,
        ]]);

        $analytics = $this->analyticsService->buildAnalytics('2026-11-23', '2026-11-24');

        // Hourly verification:
        // Hour 1 has $oAfterMidnight (3 items -> peak hour!)
        // Hour 14 has $oMon (1 item)
        $hours = $analytics['sales_by_hour']['hours'];
        $this->assertCount(24, $hours);
        $this->assertSame(1, $hours[1]['orders_count']);
        $this->assertSame($oAfterMidnight->total_amount, $hours[1]['gross_sales']);
        $this->assertTrue($hours[1]['is_peak']);
        $this->assertSame(100, $hours[1]['sales_bar_pct']);

        $this->assertSame(1, $hours[14]['orders_count']);
        $this->assertSame($oMon->total_amount, $hours[14]['gross_sales']);
        $this->assertFalse($hours[14]['is_peak']);
        $this->assertSame(33, $hours[14]['sales_bar_pct']);
        $this->assertSame(1, $analytics['sales_by_hour']['peak_hour']);

        // Weekday verification:
        // BOTH orders belong to Monday (2026-11-23, ISO weekday 1) because 2026-11-24 01:30 is before 06:00!
        // Tuesday (ISO weekday 2) has 0 orders!
        $weekdays = $analytics['sales_by_weekday']['weekdays'];
        $this->assertCount(7, $weekdays);
        $this->assertSame(1, $weekdays[0]['iso_weekday']);
        $this->assertSame('messages.analytics.weekday_1', $weekdays[0]['label_key']);
        $this->assertSame(2, $weekdays[0]['orders_count']);
        $this->assertSame($oMon->total_amount + $oAfterMidnight->total_amount, $weekdays[0]['gross_sales']);
        $this->assertSame('100.0%', $weekdays[0]['share_formatted']);
        $this->assertTrue($weekdays[0]['is_peak']);

        $this->assertSame(2, $weekdays[1]['iso_weekday']);
        $this->assertSame(0, $weekdays[1]['orders_count']);
        $this->assertSame(0, $weekdays[1]['gross_sales']);

        // Order type verification:
        // Dine-In: 1 order (25.0% of sales, 50.0% of orders)
        // Takeaway: 1 order (75.0% of sales, 50.0% of orders)
        $types = $analytics['sales_by_order_type']['types'];
        $this->assertSame(1, $types[Order::TYPE_DINE_IN]['orders_count']);
        $this->assertSame($oMon->total_amount, $types[Order::TYPE_DINE_IN]['gross_sales']);
        $this->assertSame('25.0%', $types[Order::TYPE_DINE_IN]['sales_share_formatted']);
        $this->assertSame('50.0%', $types[Order::TYPE_DINE_IN]['orders_share_formatted']);
        $this->assertSame(33, $types[Order::TYPE_DINE_IN]['sales_bar_pct']);

        $this->assertSame(1, $types[Order::TYPE_TAKEAWAY]['orders_count']);
        $this->assertSame($oAfterMidnight->total_amount, $types[Order::TYPE_TAKEAWAY]['gross_sales']);
        $this->assertSame('75.0%', $types[Order::TYPE_TAKEAWAY]['sales_share_formatted']);
        $this->assertSame('50.0%', $types[Order::TYPE_TAKEAWAY]['orders_share_formatted']);
        $this->assertSame(100, $types[Order::TYPE_TAKEAWAY]['sales_bar_pct']);

        CarbonImmutable::setTestNow();
    }

    public function test_query_count_stays_constant_between_7_day_and_90_day_ranges(): void
    {
        $setting = ShopSetting::current();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->analyticsService->buildAnalytics('2026-11-01', '2026-11-07', null, $setting);
        $queries7 = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->analyticsService->buildAnalytics('2026-09-01', '2026-11-29', null, $setting);
        $queries90 = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(8, $queries7);
        $this->assertSame($queries7, $queries90);
    }
}
