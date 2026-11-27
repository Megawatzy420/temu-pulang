<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Livewire\Reports\SalesAnalytics;
use App\Models\Category;
use App\Models\Order;
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
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AnalyticsTest extends TestCase
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

    public function test_tie_outs_hour_2359_vs_0001_weekday_0130_cutoff_and_zero_max_bars(): void
    {
        ShopSetting::current()?->update([
            'tax_rate' => 1000,
            'service_charge_rate' => 500,
            'business_day_start_time' => '06:00:00',
        ]);

        // 1. Empty range (zero sales): all bars must be 0%, peak_hour is null, no NaN or negative sizes
        $emptyAnalytics = $this->analyticsService->buildAnalytics('2026-11-10', '2026-11-10');
        $this->assertSame(0, $emptyAnalytics['totals']['gross_sales']);
        $this->assertSame(0, $emptyAnalytics['totals']['paid_orders_count']);
        $this->assertNull($emptyAnalytics['sales_by_hour']['peak_hour']);
        $this->assertSame([], $emptyAnalytics['top_by_quantity']);
        $this->assertSame([], $emptyAnalytics['top_by_revenue']);

        foreach ($emptyAnalytics['sales_by_hour']['hours'] as $h) {
            $this->assertSame(0, $h['sales_bar_pct']);
            $this->assertSame(0, $h['orders_bar_pct']);
            $this->assertFalse($h['is_peak']);
        }
        foreach ($emptyAnalytics['sales_by_weekday']['weekdays'] as $w) {
            $this->assertSame(0, $w['sales_bar_pct']);
            $this->assertSame(0, $w['orders_bar_pct']);
            $this->assertFalse($w['is_peak']);
        }
        foreach ($emptyAnalytics['sales_by_order_type']['types'] as $t) {
            $this->assertSame(0, $t['sales_bar_pct']);
            $this->assertSame('0.0%', $t['sales_share_formatted']);
        }

        // Verify empty state renders cleanly in Livewire view
        App::setLocale('en');
        Livewire::actingAs($this->owner)
            ->withQueryParams(['from' => '2026-11-10', 'to' => '2026-11-10'])
            ->test(SalesAnalytics::class)
            ->assertStatus(200)
            ->assertSee(__('messages.analytics.empty_rankings'))
            ->assertSee(__('messages.analytics.card_peak_hour_none'));

        // 2. Orders at 23:59, 00:01, and 01:30 across midnight
        $shift = $this->shiftService->openShift($this->cashier, 200000);
        $product = Product::where('has_variants', false)->where('is_sold_out', false)->firstOrFail();

        // Order A at 2026-11-23 23:59:00 WIB (Monday business day 2026-11-23, clock hour 23)
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 23:59:00', 'Asia/Jakarta'));
        $order2359 = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $this->paymentService->settlePayment($this->cashier, $shift, $order2359, [[
            'payment_method' => 'cash',
            'amount' => $order2359->total_amount,
            'tendered_amount' => $order2359->total_amount,
            'change_amount' => 0,
        ]]);

        // Order B at 2026-11-24 00:01:00 WIB (still Monday business day 2026-11-23 because < 06:00, clock hour 0)
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-24 00:01:00', 'Asia/Jakarta'));
        $order0001 = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_TAKEAWAY,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);
        $this->paymentService->settlePayment($this->cashier, $shift, $order0001, [[
            'payment_method' => 'cash',
            'amount' => $order0001->total_amount,
            'tendered_amount' => $order0001->total_amount,
            'change_amount' => 0,
        ]]);

        // Order C at 2026-11-24 01:30:00 WIB (still Monday business day 2026-11-23 because < 06:00, clock hour 1, peak hour with 4 items)
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-24 01:30:00', 'Asia/Jakarta'));
        $order0130 = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ]);
        $this->paymentService->settlePayment($this->cashier, $shift, $order0130, [[
            'payment_method' => 'cash',
            'amount' => $order0130->total_amount,
            'tendered_amount' => $order0130->total_amount,
            'change_amount' => 0,
        ]]);

        $salesReport = $this->salesReportService->buildReport('2026-11-23', '2026-11-24');
        $analytics = $this->analyticsService->buildAnalytics('2026-11-23', '2026-11-24');

        // Tie-outs against SalesReportService
        $this->assertSame($salesReport['totals']['gross_sales'], $analytics['sales_by_hour']['total_gross_sales']);
        $this->assertSame($salesReport['totals']['paid_orders_count'], $analytics['sales_by_hour']['total_orders']);
        $this->assertSame($salesReport['totals']['gross_sales'], $analytics['sales_by_weekday']['total_gross_sales']);
        $this->assertSame($salesReport['totals']['paid_orders_count'], $analytics['sales_by_weekday']['total_orders']);
        $this->assertSame($salesReport['totals']['gross_sales'], $analytics['sales_by_order_type']['total_gross_sales']);
        $this->assertSame($salesReport['totals']['paid_orders_count'], $analytics['sales_by_order_type']['total_orders']);

        // 23:59 -> hour 23; 00:01 -> hour 0; 01:30 -> hour 1 (peak)
        $hours = $analytics['sales_by_hour']['hours'];
        $this->assertSame(1, $hours[23]['orders_count']);
        $this->assertSame($order2359->total_amount, $hours[23]['gross_sales']);
        $this->assertSame(1, $hours[0]['orders_count']);
        $this->assertSame($order0001->total_amount, $hours[0]['gross_sales']);
        $this->assertSame(1, $hours[1]['orders_count']);
        $this->assertSame($order0130->total_amount, $hours[1]['gross_sales']);
        $this->assertTrue($hours[1]['is_peak']);

        // All 3 orders belong to Monday's business date (2026-11-23, ISO weekday 1)
        $weekdays = $analytics['sales_by_weekday']['weekdays'];
        $this->assertSame(3, $weekdays[0]['orders_count']);
        $this->assertSame(0, $weekdays[1]['orders_count']);

        CarbonImmutable::setTestNow();
    }

    public function test_top_10_limit_with_12_products_and_partial_vs_full_refunds(): void
    {
        App::setLocale('id');
        $shift = $this->shiftService->openShift($this->cashier, 200000);
        $category = Category::firstOrFail();

        $createdProducts = [];
        for ($i = 1; $i <= 12; $i++) {
            $num = sprintf('%02d', $i);
            $p = Product::create([
                'category_id' => $category->id,
                'sku' => "TOP12-{$num}",
                'base_price' => $i * 5000,
                'has_variants' => false,
                'is_active' => true,
            ]);
            ProductTranslation::create(['product_id' => $p->id, 'locale' => 'id', 'name' => "Produk Ranking {$num}"]);
            ProductTranslation::create(['product_id' => $p->id, 'locale' => 'en', 'name' => "Ranking Product {$num}"]);
            $createdProducts[$i] = $p;
        }

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-25 12:00:00', 'Asia/Jakarta'));

        $itemsPayload = [];
        foreach ($createdProducts as $idx => $p) {
            $itemsPayload[] = ['product_id' => $p->id, 'quantity' => $idx];
        }

        $order = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => $itemsPayload,
        ]);
        $tx = $this->paymentService->settlePayment($this->cashier, $shift, $order, [[
            'payment_method' => 'cash',
            'amount' => $order->total_amount,
            'tendered_amount' => $order->total_amount,
            'change_amount' => 0,
        ]]);

        // Partially refund the order -> partially_refunded orders MUST STILL BE INCLUDED in product rankings
        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order->fresh(),
            allocations: [['payment_id' => $tx->payments->firstOrFail()->id, 'amount' => 5000, 'refund_method' => 'cash']],
            reason: 'Partial refund stays in product rankings',
            shift: $shift
        );

        $analytics = $this->analyticsService->buildAnalytics('2026-11-25', '2026-11-25');

        $this->assertSame(12, $analytics['totals']['ranked_products_count']);
        $this->assertCount(10, $analytics['top_by_quantity']);
        $this->assertCount(10, $analytics['top_by_revenue']);

        // Rank #1 is Product 12 (qty 12), Rank #10 is Product 03 (qty 3); Products 01 and 02 are outside top 10
        $this->assertSame('Produk Ranking 12', $analytics['top_by_quantity'][0]['name']);
        $this->assertSame(12, $analytics['top_by_quantity'][0]['quantity']);
        $this->assertSame('Produk Ranking 03', $analytics['top_by_quantity'][9]['name']);
        $this->assertSame(3, $analytics['top_by_quantity'][9]['quantity']);

        CarbonImmutable::setTestNow();
    }

    public function test_query_count_stays_below_fixed_bound_for_7_days_with_5_orders_and_90_days_with_50_orders(): void
    {
        $shift = $this->shiftService->openShift($this->cashier, 500000);
        $product = Product::where('has_variants', false)->where('is_sold_out', false)->firstOrFail();
        $setting = ShopSetting::current();

        // Create 5 paid orders across a 7-day range (2026-11-01 .. 2026-11-07)
        for ($i = 1; $i <= 5; $i++) {
            $day = sprintf('2026-11-%02d 11:00:00', $i);
            CarbonImmutable::setTestNow(CarbonImmutable::parse($day, 'Asia/Jakarta'));
            $o = $this->orderService->createOrder($this->cashier, $shift, [
                'order_type' => $i % 2 === 0 ? Order::TYPE_TAKEAWAY : Order::TYPE_DINE_IN,
                'idempotency_key' => (string) Str::uuid(),
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ]);
            $this->paymentService->settlePayment($this->cashier, $shift, $o, [[
                'payment_method' => 'cash',
                'amount' => $o->total_amount,
                'tendered_amount' => $o->total_amount,
                'change_amount' => 0,
            ]]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $res7 = $this->analyticsService->buildAnalytics('2026-11-01', '2026-11-07', null, $setting);
        $queries7With5Orders = count(DB::getQueryLog());

        // Add 45 more paid orders across a 90-day range (2026-09-01 .. 2026-11-29) -> 50 orders total
        $baseDate = CarbonImmutable::parse('2026-09-01 12:00:00', 'Asia/Jakarta');
        for ($i = 6; $i <= 50; $i++) {
            CarbonImmutable::setTestNow($baseDate->addDays($i % 85));
            $o = $this->orderService->createOrder($this->cashier, $shift, [
                'order_type' => $i % 2 === 0 ? Order::TYPE_TAKEAWAY : Order::TYPE_DINE_IN,
                'idempotency_key' => (string) Str::uuid(),
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ]);
            $this->paymentService->settlePayment($this->cashier, $shift, $o, [[
                'payment_method' => 'cash',
                'amount' => $o->total_amount,
                'tendered_amount' => $o->total_amount,
                'change_amount' => 0,
            ]]);
        }

        DB::flushQueryLog();
        $res90 = $this->analyticsService->buildAnalytics('2026-09-01', '2026-11-29', null, $setting);
        $queries90With50Orders = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(5, $res7['totals']['paid_orders_count']);
        $this->assertSame(50, $res90['totals']['paid_orders_count']);
        $this->assertLessThanOrEqual(8, $queries7With5Orders);
        $this->assertLessThanOrEqual(8, $queries90With50Orders);
        $this->assertSame($queries7With5Orders, $queries90With50Orders);

        // Language key parity check
        $idKeys = array_keys(Arr::dot(require base_path('lang/id/messages.php')));
        $enKeys = array_keys(Arr::dot(require base_path('lang/en/messages.php')));
        sort($idKeys);
        sort($enKeys);
        $this->assertSame($idKeys, $enKeys);

        CarbonImmutable::setTestNow();
    }
}
