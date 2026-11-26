<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Livewire\Reports\ShiftReport;
use App\Livewire\Shifts\ShiftManager;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Services\CashMovementService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\RefundService;
use App\Services\ShiftReportService;
use App\Services\ShiftService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ShiftReportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    private ShiftService $shiftService;

    private CashMovementService $cashMovementService;

    private OrderService $orderService;

    private PaymentService $paymentService;

    private RefundService $refundService;

    private ShiftReportService $reportService;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->owner = User::where('email', 'owner@temupulang.local')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();

        $this->shiftService = app(ShiftService::class);
        $this->cashMovementService = app(CashMovementService::class);
        $this->orderService = app(OrderService::class);
        $this->paymentService = app(PaymentService::class);
        $this->refundService = app(RefundService::class);
        $this->reportService = app(ShiftReportService::class);

        $this->product = Product::where('has_variants', false)->where('is_sold_out', false)->firstOrFail();
    }

    public function test_payments_by_method_split_payments_other_visibility_and_excluded_unpaid_cancelled_orders(): void
    {
        $shift = $this->shiftService->openShift($this->cashier, 150000, 'Shift mix test');

        // 1. Split payment order (cash + qris + debit)
        $splitOrder = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 3]],
        ]);
        $total = $splitOrder->total_amount;
        $cashPart = 10000;
        $qrisPart = 15000;
        $debitPart = $total - $cashPart - $qrisPart;

        $qrisConf = $this->paymentService->createQrisConfirmation($this->cashier, $splitOrder, $qrisPart, 'QRIS-SPLIT');
        $this->paymentService->settlePayment($this->cashier, $shift, $splitOrder, [
            ['payment_method' => 'cash', 'amount' => $cashPart, 'tendered_amount' => 20000, 'change_amount' => 10000],
            ['payment_method' => 'qris', 'amount' => $qrisPart, 'qris_confirmation_id' => $qrisConf->id],
            ['payment_method' => 'debit', 'amount' => $debitPart, 'reference_number' => 'EDC-SPLIT'],
        ]);

        // 2. Unpaid awaiting_payment order (excluded from sales)
        $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ]);

        // 3. Cancelled unpaid order (excluded from sales)
        $toCancel = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_TAKEAWAY,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
        ]);
        $this->orderService->cancelOrder($toCancel, $this->owner, 'Customer left');

        // Verify when 'other' is 0, the "other" line is hidden on the page
        App::setLocale('en');
        Livewire::actingAs($this->owner)
            ->test(ShiftReport::class, ['shift' => $shift])
            ->assertStatus(200)
            ->assertDontSee(__('messages.reports.payment_other'));

        // 4. Add a 'transfer' payment order -> 'other' bucket becomes > 0 and appears on screen
        $transferOrder = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ]);
        $this->paymentService->settlePayment($this->cashier, $shift, $transferOrder, [
            ['payment_method' => 'transfer', 'amount' => $transferOrder->total_amount],
        ]);

        $report = $this->reportService->buildReport($shift->fresh());

        $this->assertSame($cashPart, $report['payments_by_method']['cash']);
        $this->assertSame($qrisPart, $report['payments_by_method']['qris']);
        $this->assertSame($debitPart, $report['payments_by_method']['debit']);
        $this->assertSame($transferOrder->total_amount, $report['payments_by_method']['other']);
        $this->assertSame(
            $cashPart + $qrisPart + $debitPart + $transferOrder->total_amount,
            $report['payments_by_method']['total']
        );
        $this->assertSame($report['sales']['gross_sales'], $report['payments_by_method']['total']);

        // Unpaid and cancelled counts
        $this->assertSame(1, $report['excluded_orders']['unpaid_count']);
        $this->assertSame(1, $report['excluded_orders']['cancelled_count']);
        $this->assertSame(2, $report['excluded_orders']['total_count']);
        $this->assertSame(2, $report['sales']['paid_orders_count']);

        Livewire::actingAs($this->owner)
            ->test(ShiftReport::class, ['shift' => $shift->fresh()])
            ->assertSee(__('messages.reports.payment_other'));
    }

    public function test_z_report_immutability_and_later_shift_refund_isolation(): void
    {
        $fixedGenAt = CarbonImmutable::parse('2026-11-26 18:00:00', 'Asia/Jakarta');

        // Shift 1: create order, settle payment, close shift with discrepancy and closing note
        $shift1 = $this->shiftService->openShift($this->cashier, 100000, 'Shift 1 open note');
        $order1 = $this->orderService->createOrder($this->cashier, $shift1, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
        ]);
        $tx1 = $this->paymentService->settlePayment($this->cashier, $shift1, $order1, [
            ['payment_method' => 'cash', 'amount' => $order1->total_amount, 'tendered_amount' => $order1->total_amount, 'change_amount' => 0],
        ]);
        $payment1 = $tx1->payments->firstOrFail();

        $expectedCash1 = $this->shiftService->calculateExpectedCash($shift1);
        $countedCash1 = $expectedCash1 - 2000;
        $closedShift1 = $this->shiftService->closeShift(
            shift: $shift1,
            closingUser: $this->cashier,
            actualCash: $countedCash1,
            closingNote: 'Kurang Rp 2.000 koin'
        );

        // Snapshot Shift 1 Z-Report right after closure
        $zReportBefore = $this->reportService->buildReport($closedShift1, $fixedGenAt);

        // Open Shift 2: refund the order from Shift 1 in Shift 2, and create new orders + refunds in Shift 2
        $shift2 = $this->shiftService->openShift($this->cashier, 150000, 'Shift 2 open note');

        // Cash refund of Shift 1's order recorded during Shift 2
        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order1->fresh(),
            allocations: [['payment_id' => $payment1->id, 'amount' => 10000, 'refund_method' => 'cash']],
            reason: 'Refunded in Shift 2',
            shift: $shift2
        );

        // New order and refund in Shift 2
        $order2 = $this->orderService->createOrder($this->cashier, $shift2, [
            'order_type' => Order::TYPE_TAKEAWAY,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ]);
        $tx2 = $this->paymentService->settlePayment($this->cashier, $shift2, $order2, [
            ['payment_method' => 'cash', 'amount' => $order2->total_amount, 'tendered_amount' => $order2->total_amount, 'change_amount' => 0],
        ]);
        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order2->fresh(),
            allocations: [['payment_id' => $tx2->payments->firstOrFail()->id, 'amount' => 5000, 'refund_method' => 'cash']],
            reason: 'Shift 2 refund',
            shift: $shift2
        );

        // Rebuild Shift 1 Z-Report: must be 100% identical to before Shift 2 existed!
        $zReportAfter = $this->reportService->buildReport($closedShift1->fresh(), $fixedGenAt);
        $this->assertSame($zReportBefore, $zReportAfter);
        $this->assertSame(0, $zReportAfter['refunds']['total']);
        $this->assertSame($order1->total_amount, $zReportAfter['sales']['net_sales']);
        $this->assertSame($countedCash1, $zReportAfter['cash_drawer']['counted_cash']);
        $this->assertSame(-2000, $zReportAfter['cash_drawer']['variance']);
        $this->assertSame('Kurang Rp 2.000 koin', $zReportAfter['cash_drawer']['closing_note']);

        // And Shift 2 X-Report records the 10,000 + 5,000 = 15,000 cash refunds
        $xReportShift2 = $this->reportService->buildReport($shift2->fresh(), $fixedGenAt);
        $this->assertSame(15000, $xReportShift2['refunds']['total']);
    }

    public function test_open_shift_shows_x_report_and_closed_shift_shows_z_report_with_links_on_shift_manager(): void
    {
        App::setLocale('id');
        $shift = $this->shiftService->openShift($this->cashier, 100000, 'Catatan buka pagi');

        // 1. ShiftManager shows X-Report link for active shift
        Livewire::actingAs($this->cashier)
            ->test(ShiftManager::class)
            ->assertSee('X-Report')
            ->assertSee(route('reports.shift', $shift));

        // 2. Open shift renders X-Report (Live) with Refresh button and Print stylesheet
        $openHtml = Livewire::actingAs($this->cashier)
            ->test(ShiftReport::class, ['shift' => $shift])
            ->assertStatus(200)
            ->assertSee('X-Report (Live)')
            ->assertSee('Segarkan Data')
            ->assertSee('Masih Buka (Aktif)')
            ->assertDontSee('Kas Fisik Dihitung (Counted Cash)')
            ->call('refreshReport')
            ->assertStatus(200)
            ->html();

        $this->assertStringContainsString('@media print', $openHtml);
        $this->assertStringContainsString('.no-print', $openHtml);
        $this->assertStringContainsString('display: none !important', $openHtml);

        // 3. Close shift -> renders Z-Report with counted cash, variance, and closing note, and ShiftManager shows Z-Report link
        $closedShift = $this->shiftService->closeShift($shift, $this->cashier, 95000, 'Kurang Rp 5.000');

        Livewire::actingAs($this->cashier)
            ->test(ShiftManager::class)
            ->assertSee('Z-Report')
            ->assertSee(route('reports.shift', $closedShift));

        Livewire::actingAs($this->cashier)
            ->test(ShiftReport::class, ['shift' => $closedShift])
            ->assertStatus(200)
            ->assertSee('Z-Report')
            ->assertDontSee('X-Report (Live)')
            ->assertDontSee('Segarkan Data')
            ->assertSee('Kas Fisik Dihitung (Counted Cash)')
            ->assertSee('95.000')
            ->assertSee('Kurang -Rp 5.000')
            ->assertSee('Kurang Rp 5.000');
    }

    public function test_authorization_owner_can_open_any_shift_cashier_only_own_shift_and_guest_redirected(): void
    {
        $otherCashier = User::factory()->cashier()->create();
        $otherShift = $this->shiftService->openShift($otherCashier, 100000);
        $this->shiftService->closeShift($otherShift, $otherCashier, 100000, 'Other cashier shift');

        $ownShift = $this->shiftService->openShift($this->cashier, 120000);

        // 1. Guest is redirected to login
        $this->get(route('reports.shift', $ownShift))
            ->assertRedirect(route('login'));

        // 2. Cashier can open their own shift
        $this->actingAs($this->cashier)
            ->get(route('reports.shift', $ownShift))
            ->assertOk();

        // 3. Cashier gets 403 for another cashier's shift (both HTTP route and Livewire mount)
        $this->actingAs($this->cashier)
            ->get(route('reports.shift', $otherShift))
            ->assertForbidden();

        Livewire::actingAs($this->cashier)
            ->test(ShiftReport::class, ['shift' => $otherShift])
            ->assertForbidden();

        // 4. Owner can open both shifts
        $this->actingAs($this->owner)
            ->get(route('reports.shift', $ownShift))
            ->assertOk();

        $this->actingAs($this->owner)
            ->get(route('reports.shift', $otherShift))
            ->assertOk();
    }

    public function test_locales_id_and_en_render_without_raw_keys_or_entities_and_query_count_stays_bounded(): void
    {
        $shift = $this->shiftService->openShift($this->cashier, 100000);
        $this->cashMovementService->recordMovement($shift, $this->cashier, 'in', 25000, 'capital_add', 'Float');
        $this->cashMovementService->recordMovement($shift, $this->cashier, 'out', 10000, 'cash_drop', 'Drop');

        for ($i = 1; $i <= 5; $i++) {
            $o = $this->orderService->createOrder($this->cashier, $shift, [
                'order_type' => Order::TYPE_DINE_IN,
                'idempotency_key' => (string) Str::uuid(),
                'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
            ]);
            $this->paymentService->settlePayment($this->cashier, $shift, $o, [
                ['payment_method' => 'cash', 'amount' => $o->total_amount, 'tendered_amount' => $o->total_amount, 'change_amount' => 0],
            ]);
        }

        // 1. Indonesian locale check
        App::setLocale('id');
        $idHtml = Livewire::actingAs($this->owner)
            ->test(ShiftReport::class, ['shift' => $shift])
            ->assertStatus(200)
            ->assertSee('Ringkasan Penjualan Shift')
            ->assertSee('Rekonsiliasi Laci Kas (Cash Drawer)')
            ->assertSee('Tambah Modal Awal')
            ->assertSee('Setor Kas ke Brankas (Cash Drop)')
            ->assertDontSee('capital_add')
            ->assertDontSee('cash_drop')
            ->assertDontSee('partially_refunded')
            ->assertDontSee('&rarr;')
            ->html();

        $this->assertStringNotContainsString('>qris<', $idHtml);

        // 2. English locale check
        App::setLocale('en');
        Livewire::actingAs($this->owner)
            ->test(ShiftReport::class, ['shift' => $shift])
            ->assertStatus(200)
            ->assertSee('Shift Sales Summary')
            ->assertSee('Cash Drawer Reconciliation')
            ->assertSee('Add Starting Float')
            ->assertSee('Safe Cash Drop')
            ->assertDontSee('Ringkasan Penjualan Shift')
            ->assertDontSee('capital_add')
            ->assertDontSee('cash_drop')
            ->assertDontSee('partially_refunded')
            ->assertDontSee('&rarr;');

        // 3. Query count bound check: 5 orders vs 50 orders
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->reportService->buildReport($shift->fresh());
        $queriesWith5 = count(DB::getQueryLog());

        for ($i = 6; $i <= 50; $i++) {
            $o = $this->orderService->createOrder($this->cashier, $shift, [
                'order_type' => Order::TYPE_DINE_IN,
                'idempotency_key' => (string) Str::uuid(),
                'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
            ]);
            $this->paymentService->settlePayment($this->cashier, $shift, $o, [
                ['payment_method' => 'cash', 'amount' => $o->total_amount, 'tendered_amount' => $o->total_amount, 'change_amount' => 0],
            ]);
        }

        DB::flushQueryLog();
        $this->reportService->buildReport($shift->fresh());
        $queriesWith50 = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(10, $queriesWith5);
        $this->assertLessThanOrEqual(10, $queriesWith50);
        $this->assertSame($queriesWith5, $queriesWith50);

        // 4. Language parity check
        $idKeys = array_keys(Arr::dot(require base_path('lang/id/messages.php')));
        $enKeys = array_keys(Arr::dot(require base_path('lang/en/messages.php')));
        sort($idKeys);
        sort($enKeys);
        $this->assertSame($idKeys, $enKeys);
    }
}
