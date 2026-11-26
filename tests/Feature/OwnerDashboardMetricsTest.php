<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Dashboard\OwnerDashboard;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\RefundTransaction;
use App\Models\Shift;
use App\Models\ShopSetting;
use App\Models\User;
use App\Services\DashboardMetricsService;
use App\Services\ShiftService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class OwnerDashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->owner = User::where('email', 'owner@temupulang.local')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();
        $this->shift = app(ShiftService::class)->openShift($this->cashier, 200000, 'Dashboard test shift');
    }

    /**
     * Helper to insert an Order with explicit status, payment_status, paid_at, and created_at.
     */
    private function createOrderRecord(
        int $totalAmount,
        string $status,
        string $paymentStatus,
        ?CarbonImmutable $paidAt,
        CarbonImmutable $createdAt,
        ?string $customerName = null,
        string $orderType = Order::TYPE_DINE_IN,
        ?int $sequence = null
    ): Order {
        $seq = $sequence ?? (Order::max('daily_sequence') + 1);
        $businessDate = $createdAt->toDateString();

        $order = Order::create([
            'ulid' => (string) Str::ulid(),
            'order_number' => sprintf('ORD-%s-%03d', str_replace('-', '', $businessDate), $seq),
            'daily_order_number' => sprintf('TP-%03d', $seq),
            'daily_sequence' => $seq,
            'business_date' => $businessDate,
            'shift_id' => $this->shift->id,
            'user_id' => $this->cashier->id,
            'order_type' => $orderType,
            'status' => Order::STATUS_DRAFT,
            'subtotal' => $totalAmount,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'service_charge_rate' => 0,
            'service_charge_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => $totalAmount,
            'customer_name' => $customerName,
            'idempotency_key' => (string) Str::uuid(),
            'paid_at' => $paidAt,
        ]);

        DB::table('orders')->where('id', $order->id)->update([
            'status' => $status,
            'payment_status' => $paymentStatus,
            'paid_at' => $paidAt?->toDateTimeString(),
            'created_at' => $createdAt->toDateTimeString(),
            'updated_at' => $createdAt->toDateTimeString(),
        ]);

        return $order->fresh();
    }

    /**
     * Helper to attach a PaymentTransaction and one or more Payment tender rows to an order.
     *
     * @param  array<int, array{method: string, amount: int, tendered?: int}>  $tenders
     */
    private function attachPayments(Order $order, array $tenders, CarbonImmutable $createdAt): PaymentTransaction
    {
        $seq = (int) PaymentTransaction::max('daily_sequence') + 1;
        $tx = PaymentTransaction::create([
            'ulid' => (string) Str::ulid(),
            'payment_transaction_number' => sprintf('PAY-%s-%04d', $createdAt->format('Ymd'), $seq),
            'daily_sequence' => $seq,
            'business_date' => $createdAt->toDateString(),
            'order_id' => $order->id,
            'shift_id' => $this->shift->id,
            'user_id' => $this->cashier->id,
            'cashier_name' => $this->cashier->name,
            'total_amount' => $order->total_amount,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', (string) Str::uuid()),
            'status' => 'completed',
        ]);

        foreach ($tenders as $tender) {
            $tendered = $tender['tendered'] ?? $tender['amount'];
            $payment = new Payment([
                'payment_transaction_id' => $tx->id,
                'shift_id' => $this->shift->id,
                'payment_method' => $tender['method'],
                'amount' => $tender['amount'],
                'tendered_amount' => $tendered,
                'change_amount' => $tendered - $tender['amount'],
            ]);
            $payment->order_id = $order->id;
            $payment->save();
        }

        return $tx;
    }

    /**
     * Helper to create a RefundTransaction at a specific timestamp.
     */
    private function createRefundRecord(Order $order, int $refundAmount, CarbonImmutable $createdAt): RefundTransaction
    {
        $seq = (int) RefundTransaction::max('daily_sequence') + 1;
        $refundTx = RefundTransaction::create([
            'ulid' => (string) Str::ulid(),
            'refund_transaction_number' => sprintf('RFD-%s-%04d', $createdAt->format('Ymd'), $seq),
            'daily_sequence' => $seq,
            'business_date' => $createdAt->toDateString(),
            'order_id' => $order->id,
            'authorized_by_user_id' => $this->owner->id,
            'total_refunded_amount' => $refundAmount,
            'reason' => 'Test refund',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', (string) Str::uuid()),
        ]);

        DB::table('refund_transactions')->where('id', $refundTx->id)->update([
            'created_at' => $createdAt->toDateTimeString(),
            'updated_at' => $createdAt->toDateTimeString(),
        ]);

        return $refundTx->fresh();
    }

    public function test_gross_refunds_net_order_count_and_avg_ticket_for_mixed_orders(): void
    {
        $now = CarbonImmutable::parse('2026-11-26 14:00:00', 'Asia/Jakarta');

        // 1. Paid order: 50,000
        $paidOrder = $this->createOrderRecord(
            totalAmount: 50000,
            status: Order::STATUS_COMPLETED,
            paymentStatus: Order::PAYMENT_STATUS_PAID,
            paidAt: $now->subHours(3),
            createdAt: $now->subHours(3),
            sequence: 1
        );

        // 2. Partially refunded order: 40,000 gross, 15,000 refunded today
        $partialOrder = $this->createOrderRecord(
            totalAmount: 40000,
            status: Order::STATUS_COMPLETED,
            paymentStatus: Order::PAYMENT_STATUS_PARTIALLY_REFUNDED,
            paidAt: $now->subHours(2),
            createdAt: $now->subHours(2),
            sequence: 2
        );
        $this->createRefundRecord($partialOrder, 15000, $now->subHour());

        // 3. Fully refunded order: 30,000 gross, 30,000 refunded today (status became cancelled on full refund)
        $refundedOrder = $this->createOrderRecord(
            totalAmount: 30000,
            status: Order::STATUS_CANCELLED,
            paymentStatus: Order::PAYMENT_STATUS_REFUNDED,
            paidAt: $now->subMinutes(90),
            createdAt: $now->subMinutes(90),
            sequence: 3
        );
        $this->createRefundRecord($refundedOrder, 30000, $now->subMinutes(30));

        // 4. Unpaid awaiting_payment order: 75,000 (excluded from sales)
        $this->createOrderRecord(
            totalAmount: 75000,
            status: Order::STATUS_AWAITING_PAYMENT,
            paymentStatus: Order::PAYMENT_STATUS_UNPAID,
            paidAt: null,
            createdAt: $now->subMinutes(20),
            sequence: 4
        );

        // 5. Unpaid cancelled order: 60,000 (excluded from sales)
        $this->createOrderRecord(
            totalAmount: 60000,
            status: Order::STATUS_CANCELLED,
            paymentStatus: Order::PAYMENT_STATUS_UNPAID,
            paidAt: null,
            createdAt: $now->subMinutes(10),
            sequence: 5
        );

        $service = app(DashboardMetricsService::class);
        $snapshot = $service->getDashboardSnapshot($now);

        // Gross = 50,000 + 40,000 + 30,000 = 120,000
        $this->assertSame(120000, $snapshot['gross_sales']);
        // Refunds = 15,000 + 30,000 = 45,000
        $this->assertSame(45000, $snapshot['refunds']);
        // Net = 120,000 - 45,000 = 75,000
        $this->assertSame(75000, $snapshot['net_sales']);
        // Paid orders count = 3
        $this->assertSame(3, $snapshot['paid_orders']);
        // Average ticket = intdiv(120000, 3) = 40000
        $this->assertSame(40000, $snapshot['avg_ticket']);
    }

    public function test_refund_made_today_for_order_paid_yesterday_reduces_todays_net_not_yesterdays(): void
    {
        $now = CarbonImmutable::parse('2026-11-26 12:00:00', 'Asia/Jakarta');
        $yesterdayPaidAt = CarbonImmutable::parse('2026-11-25 15:00:00', 'Asia/Jakarta');
        $todayRefundAt = CarbonImmutable::parse('2026-11-26 10:00:00', 'Asia/Jakarta');

        // Order paid yesterday for 100,000, refunded today for 40,000
        $yesterdayOrder = $this->createOrderRecord(
            totalAmount: 100000,
            status: Order::STATUS_COMPLETED,
            paymentStatus: Order::PAYMENT_STATUS_PARTIALLY_REFUNDED,
            paidAt: $yesterdayPaidAt,
            createdAt: $yesterdayPaidAt,
            sequence: 1
        );
        $this->createRefundRecord($yesterdayOrder, 40000, $todayRefundAt);

        // Order paid today for 90,000
        $this->createOrderRecord(
            totalAmount: 90000,
            status: Order::STATUS_PAID,
            paymentStatus: Order::PAYMENT_STATUS_PAID,
            paidAt: $now->subHour(),
            createdAt: $now->subHour(),
            sequence: 2
        );

        $service = app(DashboardMetricsService::class);
        $snapshot = $service->getDashboardSnapshot($now);

        // Yesterday: gross 100,000, refunds 0, net 100,000
        $this->assertSame(100000, $snapshot['previous_gross_sales']);
        $this->assertSame(0, $snapshot['previous_refunds']);
        $this->assertSame(100000, $snapshot['previous_net_sales']);

        // Today: gross 90,000, refunds 40,000, net 50,000
        $this->assertSame(90000, $snapshot['gross_sales']);
        $this->assertSame(40000, $snapshot['refunds']);
        $this->assertSame(50000, $snapshot['net_sales']);
    }

    public function test_business_day_boundary_places_orders_just_before_and_after_cutoff_on_different_days(): void
    {
        $setting = ShopSetting::current() ?? ShopSetting::firstOrFail();
        $setting->update([
            'business_day_start_time' => '06:00:00',
            'timezone' => 'Asia/Jakarta',
        ]);

        // Just before 06:00:00 WIB on 2026-11-26 -> belongs to 2026-11-25 business day
        $beforeCutoff = CarbonImmutable::parse('2026-11-26 05:59:59', 'Asia/Jakarta');
        $this->createOrderRecord(
            totalAmount: 35000,
            status: Order::STATUS_PAID,
            paymentStatus: Order::PAYMENT_STATUS_PAID,
            paidAt: $beforeCutoff,
            createdAt: $beforeCutoff,
            sequence: 1
        );

        // Exactly at 06:00:00 WIB on 2026-11-26 -> belongs to 2026-11-26 business day
        $atCutoff = CarbonImmutable::parse('2026-11-26 06:00:00', 'Asia/Jakarta');
        $this->createOrderRecord(
            totalAmount: 55000,
            status: Order::STATUS_PAID,
            paymentStatus: Order::PAYMENT_STATUS_PAID,
            paidAt: $atCutoff,
            createdAt: $atCutoff,
            sequence: 2
        );

        $service = app(DashboardMetricsService::class);

        // Evaluating at 05:59:59 WIB (still inside the 2026-11-25 business day)
        $earlySnapshot = $service->getDashboardSnapshot($beforeCutoff);
        $this->assertSame(35000, $earlySnapshot['gross_sales']);
        $this->assertSame(1, $earlySnapshot['paid_orders']);

        // Evaluating at 10:00:00 WIB on 2026-11-26 (2026-11-26 business day)
        $daySnapshot = $service->getDashboardSnapshot(CarbonImmutable::parse('2026-11-26 10:00:00', 'Asia/Jakarta'));
        $this->assertSame(55000, $daySnapshot['gross_sales']);
        $this->assertSame(1, $daySnapshot['paid_orders']);
        $this->assertSame(35000, $daySnapshot['previous_gross_sales']);
        $this->assertSame(35000, $daySnapshot['previous_net_sales']);
    }

    public function test_payment_mix_counts_bill_amount_per_method_including_split_payments_and_other_bucket(): void
    {
        $now = CarbonImmutable::parse('2026-11-26 14:00:00', 'Asia/Jakarta');

        // Order 1: Cash payment where customer handed over 100,000 for a 45,000 bill (must count 45,000, not 100,000)
        $order1 = $this->createOrderRecord(
            totalAmount: 45000,
            status: Order::STATUS_PAID,
            paymentStatus: Order::PAYMENT_STATUS_PAID,
            paidAt: $now->subHours(2),
            createdAt: $now->subHours(2),
            sequence: 1
        );
        $this->attachPayments($order1, [
            ['method' => 'cash', 'amount' => 45000, 'tendered' => 100000],
        ], $now->subHours(2));

        // Order 2: Split payment (20,000 cash + 30,000 qris + 25,000 debit)
        $order2 = $this->createOrderRecord(
            totalAmount: 75000,
            status: Order::STATUS_PAID,
            paymentStatus: Order::PAYMENT_STATUS_PAID,
            paidAt: $now->subHour(),
            createdAt: $now->subHour(),
            sequence: 2
        );
        $this->attachPayments($order2, [
            ['method' => 'cash', 'amount' => 20000, 'tendered' => 50000],
            ['method' => 'qris', 'amount' => 30000],
            ['method' => 'debit', 'amount' => 25000],
        ], $now->subHour());

        // Order 3: Transfer payment (15,000) -> goes into 'other' bucket; refund on Order 1 does not subtract from payment mix
        $order3 = $this->createOrderRecord(
            totalAmount: 15000,
            status: Order::STATUS_PAID,
            paymentStatus: Order::PAYMENT_STATUS_PARTIALLY_REFUNDED,
            paidAt: $now->subMinutes(30),
            createdAt: $now->subMinutes(30),
            sequence: 3
        );
        $this->attachPayments($order3, [
            ['method' => 'transfer', 'amount' => 15000],
        ], $now->subMinutes(30));
        $this->createRefundRecord($order3, 5000, $now->subMinutes(10));

        $service = app(DashboardMetricsService::class);
        $window = $service->resolveBusinessDayWindow($now);
        $mix = $service->paymentMix($window['start'], $window['end']);

        $this->assertSame(65000, $mix['cash']);
        $this->assertSame(30000, $mix['qris']);
        $this->assertSame(25000, $mix['debit']);
        $this->assertSame(15000, $mix['other']);
        $this->assertSame(135000, $mix['total']);
    }

    public function test_percent_change_computes_rise_fall_and_dash_with_integer_only_rounding(): void
    {
        $service = app(DashboardMetricsService::class);

        // 1. Previous day is 0 -> dash
        $zeroPrev = $service->percentChangeInTenths(150000, 0);
        $this->assertNull($zeroPrev['tenths']);
        $this->assertSame('none', $zeroPrev['direction']);
        $this->assertSame('—', $zeroPrev['formatted']);

        // 2. Rise with half-up integer rounding: 30,000 -> 50,000 (+66.666...% -> ↑ 66.7%)
        $rise = $service->percentChangeInTenths(50000, 30000);
        $this->assertSame(667, $rise['tenths']);
        $this->assertSame('up', $rise['direction']);
        $this->assertSame('↑ 66.7%', $rise['formatted']);

        // 3. Fall with half-up integer rounding: 30,000 -> 20,000 (-33.333...% -> ↓ 33.3%)
        $fall = $service->percentChangeInTenths(20000, 30000);
        $this->assertSame(-333, $fall['tenths']);
        $this->assertSame('down', $fall['direction']);
        $this->assertSame('↓ 33.3%', $fall['formatted']);
    }

    public function test_recent_orders_limit_five_newest_first_and_best_sellers_top_four_excluding_fully_refunded(): void
    {
        $now = CarbonImmutable::parse('2026-11-26 15:00:00', 'Asia/Jakarta');
        $product = Product::firstOrFail();

        // Create 6 orders in the business day; 5 paid/partially_refunded + 1 fully refunded
        for ($i = 1; $i <= 6; $i++) {
            $isFullyRefunded = ($i === 6);
            $order = $this->createOrderRecord(
                totalAmount: $i * 20000,
                status: $isFullyRefunded ? Order::STATUS_CANCELLED : Order::STATUS_COMPLETED,
                paymentStatus: $isFullyRefunded ? Order::PAYMENT_STATUS_REFUNDED : Order::PAYMENT_STATUS_PAID,
                paidAt: $now->subMinutes(70 - ($i * 10)),
                createdAt: $now->subMinutes(70 - ($i * 10)),
                customerName: "Cust {$i}",
                orderType: $i % 2 === 0 ? Order::TYPE_TAKEAWAY : Order::TYPE_DINE_IN,
                sequence: $i
            );

            if ($i <= 5) {
                $qty = $i * 2; // Menu 1 -> 2, Menu 2 -> 4, Menu 3 -> 6, Menu 4 -> 8, Menu 5 -> 10
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'sku' => "SKU-BS-{$i}",
                    'product_name_id' => "Menu ID {$i}",
                    'product_name_en' => "Menu EN {$i}",
                    'unit_price' => 10000,
                    'modifiers_unit_price' => 0,
                    'final_unit_price' => 10000,
                    'quantity' => $qty,
                    'subtotal' => $qty * 10000,
                ]);
            } else {
                // Fully refunded order with huge quantity (100) must be excluded from best sellers!
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'sku' => 'SKU-BS-REFUNDED',
                    'product_name_id' => 'Menu Refunded',
                    'product_name_en' => 'Refunded Menu',
                    'unit_price' => 10000,
                    'modifiers_unit_price' => 0,
                    'final_unit_price' => 10000,
                    'quantity' => 100,
                    'subtotal' => 1000000,
                ]);
            }
        }

        $service = app(DashboardMetricsService::class);
        $window = $service->resolveBusinessDayWindow($now);

        // Recent orders: limit 5, newest first (Orders 6, 5, 4, 3, 2; Order 1 excluded)
        $recent = $service->recentOrders($window['start'], $window['end'], $window['timezone']);
        $this->assertCount(5, $recent);
        $this->assertSame('Cust 6', $recent[0]['customer_name']);
        $this->assertSame('Cust 2', $recent[4]['customer_name']);

        // Best sellers: top 4 from paid/partially_refunded (Menus 5, 4, 3, 2; Menu 1 and Refunded excluded)
        App::setLocale('id');
        $best = $service->bestSellers($window['start'], $window['end']);
        $this->assertCount(4, $best);
        $this->assertSame('Menu ID 5', $best[0]['name']);
        $this->assertSame(10, $best[0]['sold_count']);
        $this->assertSame(100000, $best[0]['revenue']);
        $this->assertSame('Menu ID 2', $best[3]['name']);
        $this->assertSame(4, $best[3]['sold_count']);
        $this->assertSame(40000, $best[3]['revenue']);
    }

    public function test_empty_day_shows_empty_states_and_zeros_and_no_hardcoded_demo_content_remains(): void
    {
        App::setLocale('id');
        $idHtml = Livewire::actingAs($this->owner)
            ->test(OwnerDashboard::class)
            ->assertStatus(200)
            ->assertSee('Belum ada penjualan hari ini')
            ->assertSee('—')
            ->assertDontSee('TP-0104')
            ->assertDontSee('TP-0103')
            ->assertDontSee('Demo Simulation')
            ->assertDontSee('Simulasi Demo')
            ->assertDontSee('SIMULASI')
            ->assertDontSee('3.420.000')
            ->assertDontSee('2.990.000')
            ->assertDontSee('Pelanggan 04')
            ->html();

        $this->assertStringNotContainsString('SIMULASI', strtoupper($idHtml));

        App::setLocale('en');
        Livewire::actingAs($this->owner)
            ->test(OwnerDashboard::class)
            ->assertStatus(200)
            ->assertSee('No sales yet today')
            ->assertSee('Payment Mix (Money Received)')
            ->assertDontSee('TP-0104')
            ->assertDontSee('Demo Simulation')
            ->assertDontSee('3.420.000')
            ->assertDontSee('Pelanggan 04');
    }

    public function test_cashier_gets_403_locales_render_query_count_stays_bounded_and_lang_keys_match(): void
    {
        // 1. Cashier gets 403 on Livewire component
        Livewire::actingAs($this->cashier)
            ->test(OwnerDashboard::class)
            ->assertForbidden();

        // 2. Query count stays below a fixed bound with 5 vs 50 orders
        $now = CarbonImmutable::now('Asia/Jakarta');
        $product = Product::firstOrFail();

        for ($i = 1; $i <= 5; $i++) {
            $o = $this->createOrderRecord(25000, Order::STATUS_PAID, Order::PAYMENT_STATUS_PAID, $now, $now, "C{$i}", Order::TYPE_DINE_IN, $i);
            OrderItem::create([
                'order_id' => $o->id,
                'product_id' => $product->id,
                'sku' => $product->sku,
                'product_name_id' => 'Kopi',
                'product_name_en' => 'Coffee',
                'unit_price' => 25000,
                'modifiers_unit_price' => 0,
                'final_unit_price' => 25000,
                'quantity' => 1,
                'subtotal' => 25000,
            ]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        Livewire::actingAs($this->owner)->test(OwnerDashboard::class)->assertStatus(200);
        $queriesWith5 = count(DB::getQueryLog());

        for ($i = 6; $i <= 50; $i++) {
            $o = $this->createOrderRecord(25000, Order::STATUS_PAID, Order::PAYMENT_STATUS_PAID, $now, $now, "C{$i}", Order::TYPE_DINE_IN, $i);
            OrderItem::create([
                'order_id' => $o->id,
                'product_id' => $product->id,
                'sku' => $product->sku,
                'product_name_id' => 'Kopi',
                'product_name_en' => 'Coffee',
                'unit_price' => 25000,
                'modifiers_unit_price' => 0,
                'final_unit_price' => 25000,
                'quantity' => 1,
                'subtotal' => 25000,
            ]);
        }

        DB::flushQueryLog();
        Livewire::actingAs($this->owner)->test(OwnerDashboard::class)->assertStatus(200);
        $queriesWith50 = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(15, $queriesWith5);
        $this->assertLessThanOrEqual(15, $queriesWith50);
        $this->assertLessThanOrEqual(1, abs($queriesWith50 - $queriesWith5));

        // 3. Language key parity check
        $idKeys = array_keys(Arr::dot(require base_path('lang/id/messages.php')));
        $enKeys = array_keys(Arr::dot(require base_path('lang/en/messages.php')));
        sort($idKeys);
        sort($enKeys);
        $this->assertSame($idKeys, $enKeys);
    }
}
