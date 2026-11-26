<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\ShopSetting;
use App\Models\User;
use App\Services\CashMovementService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\RefundService;
use App\Services\ShiftReportService;
use App\Services\ShiftService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShiftReportServiceTest extends TestCase
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
    }

    public function test_tie_out_formula_matches_shift_expected_cash_with_sales_refunds_and_reversed_movements(): void
    {
        ShopSetting::current()?->update([
            'tax_rate' => 1000, // 10%
            'service_charge_rate' => 500, // 5%
        ]);

        $shift = $this->shiftService->openShift($this->cashier, 200000, 'Unit test opening float');
        $product = Product::where('has_variants', false)->where('is_sold_out', false)->firstOrFail();

        // 1. Manual cash in (+50,000)
        $this->cashMovementService->recordMovement($shift, $this->cashier, 'in', 50000, 'capital_add', 'Tambah receh');

        // 2. Manual cash out (-30,000)
        $this->cashMovementService->recordMovement($shift, $this->cashier, 'out', 30000, 'expense', 'Beli es batu');

        // 3. Another manual cash out (-15,000) that gets reversed (+15,000 reversal)
        $toReverse = $this->cashMovementService->recordMovement($shift, $this->cashier, 'out', 15000, 'cash_drop', 'Salah setor');
        $this->cashMovementService->reverseMovement($toReverse, $this->cashier, 'Batal setor');

        // 4. Cash order #1 (2 items) -> partially refunded in cash (10,000)
        $cashOrder1 = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);
        $tx1 = $this->paymentService->settlePayment($this->cashier, $shift, $cashOrder1, [[
            'payment_method' => 'cash',
            'amount' => $cashOrder1->total_amount,
            'tendered_amount' => $cashOrder1->total_amount + 20000,
            'change_amount' => 20000,
        ]]);
        $cashPay1 = $tx1->payments->firstOrFail();
        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $cashOrder1->fresh(),
            allocations: [['payment_id' => $cashPay1->id, 'amount' => 10000, 'refund_method' => 'cash']],
            reason: 'Partial cash refund',
            shift: $shift
        );

        // 5. Cash order #2 (1 item) -> fully refunded in cash
        $cashOrder2 = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_TAKEAWAY,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $tx2 = $this->paymentService->settlePayment($this->cashier, $shift, $cashOrder2, [[
            'payment_method' => 'cash',
            'amount' => $cashOrder2->total_amount,
            'tendered_amount' => $cashOrder2->total_amount,
            'change_amount' => 0,
        ]]);
        $cashPay2 = $tx2->payments->firstOrFail();
        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $cashOrder2->fresh(),
            allocations: [['payment_id' => $cashPay2->id, 'amount' => $cashOrder2->total_amount, 'refund_method' => 'cash']],
            reason: 'Full cash refund',
            shift: $shift
        );

        // 6. QRIS order (1 item)
        $qrisOrder = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $qrisConf = $this->paymentService->createQrisConfirmation($this->cashier, $qrisOrder, $qrisOrder->total_amount, 'QRIS-U1');
        $this->paymentService->settlePayment($this->cashier, $shift, $qrisOrder, [[
            'payment_method' => 'qris',
            'amount' => $qrisOrder->total_amount,
            'qris_confirmation_id' => $qrisConf->id,
        ]]);

        // 7. Debit order (1 item)
        $debitOrder = $this->orderService->createOrder($this->cashier, $shift, [
            'order_type' => Order::TYPE_TAKEAWAY,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $this->paymentService->settlePayment($this->cashier, $shift, $debitOrder, [[
            'payment_method' => 'debit',
            'amount' => $debitOrder->total_amount,
            'reference_number' => 'EDC-U1',
        ]]);

        $shift = $shift->fresh();
        $report = $this->reportService->buildReport($shift, CarbonImmutable::parse('2026-11-26 16:00:00', 'Asia/Jakarta'));

        $drawer = $report['cash_drawer'];
        $computedExpected = $drawer['opening_cash']
            + $drawer['manual_cash_in']
            - $drawer['manual_cash_out']
            + $drawer['cash_sales']
            - $drawer['cash_refunds'];

        $this->assertSame($computedExpected, $drawer['expected_cash']);
        $this->assertSame($shift->currentExpectedCash(), $drawer['expected_cash']);
        $this->assertSame($this->shiftService->calculateExpectedCash($shift), $drawer['expected_cash']);

        // Sales & Net sales check
        $expectedGross = $cashOrder1->total_amount + $cashOrder2->total_amount + $qrisOrder->total_amount + $debitOrder->total_amount;
        $expectedRefunds = 10000 + $cashOrder2->total_amount;
        $this->assertSame($expectedGross, $report['sales']['gross_sales']);
        $this->assertSame($expectedRefunds, $report['refunds']['total']);
        $this->assertSame($expectedGross - $expectedRefunds, $report['sales']['net_sales']);
        $this->assertSame(4, $report['sales']['paid_orders_count']);
        $this->assertSame(intdiv($expectedGross, 4), $report['sales']['avg_ticket']);
    }
}
