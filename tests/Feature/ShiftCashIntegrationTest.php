<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\PaymentRefund;
use App\Models\Product;
use App\Models\RefundTransaction;
use App\Models\Shift;
use App\Models\User;
use App\Services\CashMovementService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ShiftService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShiftCashIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $cashier;

    protected ShiftService $shiftService;

    protected CashMovementService $cashMovementService;

    protected OrderService $orderService;

    protected PaymentService $paymentService;

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
    }

    protected function createOrder(Shift $shift, int $quantity = 1): Order
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();

        return $this->orderService->createOrder(
            cashier: $this->cashier,
            shift: $shift,
            orderPayload: [
                'order_type' => Order::TYPE_DINE_IN,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => $quantity],
                ],
            ]
        );
    }

    public function test_cash_only_payments_integrate_into_expected_cash(): void
    {
        $shift = $this->shiftService->openShift(
            user: $this->cashier,
            openingCash: 100000,
            openingNote: 'Kas awal modal'
        );

        $this->assertEquals(100000, $this->shiftService->calculateExpectedCash($shift));

        // Order 1: Cash payment exact
        $order1 = $this->createOrder($shift, quantity: 1);
        $amount1 = $order1->total_amount;

        $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $shift,
            order: $order1,
            tenders: [
                ['payment_method' => 'cash', 'amount' => $amount1, 'tendered_amount' => $amount1, 'change_amount' => 0],
            ]
        );

        // Order 2: Cash payment exact
        $order2 = $this->createOrder($shift, quantity: 1);
        $amount2 = $order2->total_amount;

        $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $shift,
            order: $order2,
            tenders: [
                ['payment_method' => 'cash', 'amount' => $amount2, 'tendered_amount' => $amount2, 'change_amount' => 0],
            ]
        );

        $shift = $shift->fresh();
        $expectedTotalCashSales = $amount1 + $amount2;

        $this->assertEquals($expectedTotalCashSales, $shift->totalCashPayments());
        $this->assertEquals(100000 + $expectedTotalCashSales, $shift->currentExpectedCash());
        $this->assertEquals(100000 + $expectedTotalCashSales, $this->shiftService->calculateExpectedCash($shift));
    }

    public function test_cash_change_correctly_affects_net_cash(): void
    {
        $shift = $this->shiftService->openShift(
            user: $this->cashier,
            openingCash: 100000
        );

        $order = $this->createOrder($shift, quantity: 1);
        $billAmount = $order->total_amount;
        $tendered = $billAmount + 50000;
        $change = 50000;

        $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $shift,
            order: $order,
            tenders: [
                ['payment_method' => 'cash', 'amount' => $billAmount, 'tendered_amount' => $tendered, 'change_amount' => $change],
            ]
        );

        $shift = $shift->fresh();

        // Customer gave 50k extra and got 50k back, so net cash added to drawer is billAmount
        $this->assertEquals($billAmount, $shift->totalCashPayments());
        $this->assertEquals($tendered, $shift->totalCashTendered());
        $this->assertEquals($change, $shift->totalCashChange());
        $this->assertEquals(100000 + $billAmount, $shift->currentExpectedCash());
    }

    public function test_non_cash_payments_do_not_affect_physical_cash_drawer(): void
    {
        $shift = $this->shiftService->openShift(
            user: $this->cashier,
            openingCash: 100000
        );

        // QRIS payment
        $order1 = $this->createOrder($shift, quantity: 1);
        $qris = $this->paymentService->createQrisConfirmation(
            cashier: $this->cashier,
            order: $order1,
            expectedAmount: $order1->total_amount
        );
        $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $shift,
            order: $order1,
            tenders: [
                [
                    'payment_method' => 'qris',
                    'amount' => $order1->total_amount,
                    'tendered_amount' => $order1->total_amount,
                    'qris_confirmation_id' => $qris->id,
                ],
            ]
        );

        // Debit payment
        $order2 = $this->createOrder($shift, quantity: 1);
        $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $shift,
            order: $order2,
            tenders: [
                [
                    'payment_method' => 'debit',
                    'amount' => $order2->total_amount,
                    'tendered_amount' => $order2->total_amount,
                    'reference_number' => 'EDC-MANDIRI-12345',
                ],
            ]
        );

        $shift = $shift->fresh();

        // Non-cash sales recorded
        $expectedNonCash = $order1->total_amount + $order2->total_amount;
        $this->assertEquals($expectedNonCash, $shift->totalNonCashPayments());
        $this->assertEquals(0, $shift->totalCashPayments());

        // Expected cash drawer remains strictly opening cash!
        $this->assertEquals(100000, $shift->currentExpectedCash());
        $this->assertEquals(100000, $this->shiftService->calculateExpectedCash($shift));
    }

    public function test_split_payments_only_add_cash_portion_to_drawer(): void
    {
        $shift = $this->shiftService->openShift(
            user: $this->cashier,
            openingCash: 100000
        );

        $order = $this->createOrder($shift, quantity: 2);
        $total = $order->total_amount;
        $this->assertGreaterThan(10000, $total);

        $cashPortion = 10000;
        $qrisPortion = $total - $cashPortion;

        $qris = $this->paymentService->createQrisConfirmation(
            cashier: $this->cashier,
            order: $order,
            expectedAmount: $qrisPortion
        );

        $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $shift,
            order: $order,
            tenders: [
                ['payment_method' => 'cash', 'amount' => $cashPortion, 'tendered_amount' => $cashPortion + 5000, 'change_amount' => 5000],
                ['payment_method' => 'qris', 'amount' => $qrisPortion, 'tendered_amount' => $qrisPortion, 'qris_confirmation_id' => $qris->id],
            ]
        );

        $shift = $shift->fresh();

        $this->assertEquals($cashPortion, $shift->totalCashPayments());
        $this->assertEquals($qrisPortion, $shift->totalNonCashPayments());
        $this->assertEquals(100000 + $cashPortion, $shift->currentExpectedCash());
    }

    public function test_full_reconciliation_formula_with_manual_movements_and_cash_refund(): void
    {
        $shift = $this->shiftService->openShift(
            user: $this->cashier,
            openingCash: 100000 // Float: 100.000
        );

        // 1. Manual Cash Inflow (+50.000)
        $this->cashMovementService->recordMovement(
            shift: $shift,
            user: $this->cashier,
            type: 'in',
            amount: 50000,
            category: 'capital_add',
            description: 'Uang kembalian receh'
        );

        // 2. Manual Cash Outflow (-20.000)
        $this->cashMovementService->recordMovement(
            shift: $shift,
            user: $this->cashier,
            type: 'out',
            amount: 20000,
            category: 'expense',
            description: 'Beli galon air'
        );

        // 3. Cash Sale (+order total)
        $order = $this->createOrder($shift, quantity: 1);
        $cashSale = $order->total_amount;
        $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $shift,
            order: $order,
            tenders: [
                ['payment_method' => 'cash', 'amount' => $cashSale, 'tendered_amount' => $cashSale],
            ]
        );

        // 4. Non-cash Sale (does not affect drawer)
        $orderNonCash = $this->createOrder($shift, quantity: 1);
        $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $shift,
            order: $orderNonCash,
            tenders: [
                ['payment_method' => 'debit', 'amount' => $orderNonCash->total_amount, 'tendered_amount' => $orderNonCash->total_amount],
            ]
        );

        // Expected before refund: 100.000 + 50.000 - 20.000 + cashSale = 130.000 + cashSale
        $this->assertEquals(130000 + $cashSale, $shift->fresh()->currentExpectedCash());

        // 5. Simulate future cash refund record (prepared for Task 5.4)
        $rt = RefundTransaction::create([
            'ulid' => (string) Str::ulid(),
            'refund_transaction_number' => 'RFD-20261114-9999',
            'daily_sequence' => 999,
            'business_date' => now()->toDateString(),
            'order_id' => $order->id,
            'authorized_by_user_id' => $this->owner->id,
            'total_refunded_amount' => 15000,
            'reason' => 'Simulasi refund tunai',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'mock-rfd-fp'),
        ]);

        $cashPayment = $order->payments()->where('payment_method', 'cash')->firstOrFail();
        PaymentRefund::create([
            'refund_transaction_id' => $rt->id,
            'payment_id' => $cashPayment->id,
            'order_id' => $order->id,
            'shift_id' => $shift->id, // attributed to this register shift
            'original_payment_method' => 'cash',
            'refund_method' => 'cash', // Cash leaves drawer
            'amount' => 15000,
        ]);

        $shift = $shift->fresh();
        $this->assertEquals(15000, $shift->totalCashRefunds());

        // Expected after refund: 130.000 + cashSale - 15.000 = 115.000 + cashSale
        $expectedCash = 115000 + $cashSale;
        $this->assertEquals($expectedCash, $shift->currentExpectedCash());
        $this->assertEquals($expectedCash, $this->shiftService->calculateExpectedCash($shift));

        // Test structured cash summary helper
        $summary = $this->shiftService->getCashSummary($shift);
        $this->assertEquals(100000, $summary['opening_cash']);
        $this->assertEquals(50000, $summary['manual_cash_in']);
        $this->assertEquals(20000, $summary['manual_cash_out']);
        $this->assertEquals($cashSale, $summary['cash_sales']);
        $this->assertEquals(15000, $summary['cash_refunds']);
        $this->assertEquals($orderNonCash->total_amount, $summary['non_cash_sales']);
        $this->assertEquals($expectedCash, $summary['expected_cash']);
    }

    public function test_payments_do_not_duplicate_cash_movements(): void
    {
        $shift = $this->shiftService->openShift(
            user: $this->cashier,
            openingCash: 100000
        );

        $initialMovementsCount = CashMovement::count();

        $order = $this->createOrder($shift, quantity: 1);
        $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $shift,
            order: $order,
            tenders: [
                ['payment_method' => 'cash', 'amount' => $order->total_amount, 'tendered_amount' => $order->total_amount],
            ]
        );

        // Payments must NOT create rows in cash_movements table
        $this->assertEquals($initialMovementsCount, CashMovement::count());
    }

    public function test_shift_closing_reconciliation_records_discrepancy_and_audit_log(): void
    {
        $shift = $this->shiftService->openShift(
            user: $this->cashier,
            openingCash: 100000
        );

        $order = $this->createOrder($shift, quantity: 1);
        $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $shift,
            order: $order,
            tenders: [
                ['payment_method' => 'cash', 'amount' => $order->total_amount, 'tendered_amount' => $order->total_amount],
            ]
        );

        $expected = 100000 + $order->total_amount;
        $actualCount = $expected - 5000; // 5.000 short

        // Closing with discrepancy without note is rejected
        try {
            $this->shiftService->closeShift(
                shift: $shift,
                closingUser: $this->cashier,
                actualCash: $actualCount,
                closingNote: ''
            );
            $this->fail('Should have required closing note on discrepancy');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Catatan penutupan wajib diisi', $e->getMessage());
        }

        // Closing with valid note succeeds
        $closedShift = $this->shiftService->closeShift(
            shift: $shift,
            closingUser: $this->cashier,
            actualCash: $actualCount,
            closingNote: 'Selisih Rp 5.000 uang receh koin hilang'
        );

        $this->assertEquals('closed', $closedShift->status);
        $this->assertEquals($expected, $closedShift->expected_cash);
        $this->assertEquals($actualCount, $closedShift->actual_cash);
        $this->assertEquals(-5000, $closedShift->discrepancy);
        $this->assertEquals('Selisih Rp 5.000 uang receh koin hilang', $closedShift->closing_note);

        // Audit log preserves full reconciliation properties
        $log = ActivityLog::where('action', 'shift.closed')
            ->where('subject_id', $shift->id)
            ->latest()
            ->firstOrFail();

        $this->assertEquals($order->total_amount, $log->properties['cash_sales']);
        $this->assertEquals($expected, $log->properties['expected_cash']);
        $this->assertEquals($actualCount, $log->properties['actual_cash']);
        $this->assertEquals(-5000, $log->properties['discrepancy']);
    }
}
