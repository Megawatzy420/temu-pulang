<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\RefundTransaction;
use App\Models\Shift;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\RefundService;
use App\Services\ShiftService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class RefundServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $cashier;

    protected Shift $shift;

    protected OrderService $orderService;

    protected PaymentService $paymentService;

    protected RefundService $refundService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->owner = User::where('email', 'owner@temupulang.local')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();

        $this->orderService = app(OrderService::class);
        $this->paymentService = app(PaymentService::class);
        $this->refundService = app(RefundService::class);

        $this->shift = app(ShiftService::class)->openShift(
            user: $this->cashier,
            openingCash: 100_000,
            openingNote: 'Kas awal test refund'
        );
    }

    // ---------------------------------------------------------------------------
    // Test Helper: create a fully paid order with cash
    // ---------------------------------------------------------------------------

    protected function createPaidOrder(int $quantity = 1, ?Shift $shift = null): Order
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();

        $order = $this->orderService->createOrder(
            cashier: $this->cashier,
            shift: $shift ?? $this->shift,
            orderPayload: [
                'order_type' => Order::TYPE_DINE_IN,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => $quantity],
                ],
            ]
        );

        $total = $order->total_amount;
        $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $shift ?? $this->shift,
            order: $order,
            tenders: [
                [
                    'payment_method' => 'cash',
                    'amount' => $total,
                    'tendered_amount' => $total + 5_000,
                    'change_amount' => 5_000,
                ],
            ]
        );

        return $order->fresh();
    }

    protected function getFirstPayment(Order $order): Payment
    {
        return Payment::where('order_id', $order->id)->firstOrFail();
    }

    // ---------------------------------------------------------------------------
    // 1. Full Cash Refund
    // ---------------------------------------------------------------------------

    public function test_full_cash_refund_succeeds(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);
        $total = $payment->amount;

        $refundTrx = $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $total, 'refund_method' => 'cash'],
            ],
            reason: 'Pelanggan meminta pembatalan',
            shift: $this->shift
        );

        $this->assertInstanceOf(RefundTransaction::class, $refundTrx);
        $this->assertEquals($total, $refundTrx->total_refunded_amount);
        $this->assertStringStartsWith('RFD-', $refundTrx->refund_transaction_number);
        $this->assertEquals(1, $refundTrx->daily_sequence);
        $this->assertNotNull($refundTrx->idempotency_key);
        $this->assertNotNull($refundTrx->request_fingerprint);

        // Order payment_status should be 'refunded'
        $order->refresh();
        $this->assertEquals(Order::PAYMENT_STATUS_REFUNDED, $order->payment_status);

        // Child PaymentRefund record
        $this->assertDatabaseHas('payment_refunds', [
            'refund_transaction_id' => $refundTrx->id,
            'payment_id' => $payment->id,
            'order_id' => $order->id,
            'refund_method' => 'cash',
            'amount' => $total,
        ]);
    }

    // ---------------------------------------------------------------------------
    // 2. Partial Cash Refund
    // ---------------------------------------------------------------------------

    public function test_partial_cash_refund_updates_payment_status_to_partially_refunded(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);
        $partialAmount = (int) floor($payment->amount / 2);

        $refundTrx = $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $partialAmount, 'refund_method' => 'cash'],
            ],
            reason: 'Refund sebagian atas permintaan pelanggan',
            shift: $this->shift
        );

        $this->assertEquals($partialAmount, $refundTrx->total_refunded_amount);

        $order->refresh();
        $this->assertEquals(Order::PAYMENT_STATUS_PARTIALLY_REFUNDED, $order->payment_status);

        // Remaining refundable balance should decrease
        $remaining = $payment->fresh()->remainingRefundableBalance();
        $this->assertEquals($payment->amount - $partialAmount, $remaining);
    }

    // ---------------------------------------------------------------------------
    // 3. Non-cash Refund (QRIS reversal — no shift required)
    // ---------------------------------------------------------------------------

    public function test_non_cash_refund_succeeds_without_active_shift(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);
        $total = $payment->amount;

        // Process refund without passing a shift
        $refundTrx = $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $total, 'refund_method' => 'qris_reversal'],
            ],
            reason: 'Transaksi QRIS dibatalkan',
            shift: null
        );

        $this->assertEquals($total, $refundTrx->total_refunded_amount);
        $order->refresh();
        $this->assertEquals(Order::PAYMENT_STATUS_REFUNDED, $order->payment_status);

        // PaymentRefund shift_id should be null for non-cash
        $refund = PaymentRefund::where('refund_transaction_id', $refundTrx->id)->firstOrFail();
        $this->assertNull($refund->shift_id);
        $this->assertEquals('qris_reversal', $refund->refund_method);
    }

    // ---------------------------------------------------------------------------
    // 4. Manual transfer refund
    // ---------------------------------------------------------------------------

    public function test_manual_transfer_refund_succeeds(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);

        $refundTrx = $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $payment->amount, 'refund_method' => 'manual_transfer'],
            ],
            reason: 'Pengembalian via transfer bank',
            shift: null
        );

        $this->assertEquals($payment->amount, $refundTrx->total_refunded_amount);
        $refund = PaymentRefund::where('refund_transaction_id', $refundTrx->id)->firstOrFail();
        $this->assertEquals('manual_transfer', $refund->refund_method);
        $this->assertNull($refund->shift_id);
    }

    // ---------------------------------------------------------------------------
    // 5. Multiple allocation lines (split refund within single payment)
    // ---------------------------------------------------------------------------

    public function test_multiple_allocation_lines_in_one_refund_transaction(): void
    {
        // We need two separate payments — create order with two items but
        // the architecture has one PaymentTransaction per order, however we
        // can have multiple Payment tenders on that transaction.
        // Create a split-payment order: cash + transfer
        $product = Product::where('is_sold_out', false)->firstOrFail();

        $order = $this->orderService->createOrder(
            cashier: $this->cashier,
            shift: $this->shift,
            orderPayload: [
                'order_type' => Order::TYPE_DINE_IN,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 2],
                ],
            ]
        );

        $total = $order->total_amount;
        $cashPart = (int) floor($total / 2);
        $transferPart = $total - $cashPart;

        $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order,
            tenders: [
                [
                    'payment_method' => 'cash',
                    'amount' => $cashPart,
                    'tendered_amount' => $cashPart,
                    'change_amount' => 0,
                ],
                [
                    'payment_method' => 'transfer',
                    'amount' => $transferPart,
                    'tendered_amount' => $transferPart,
                ],
            ]
        );

        $order->refresh();
        $payments = Payment::where('order_id', $order->id)->orderBy('id')->get();
        $this->assertCount(2, $payments);

        $cashPayment = $payments->firstWhere('payment_method', 'cash');
        $transferPayment = $payments->firstWhere('payment_method', 'transfer');

        // Refund both: cash portion via cash, transfer portion via manual_transfer
        $refundTrx = $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $cashPayment->id, 'amount' => $cashPayment->amount, 'refund_method' => 'cash'],
                ['payment_id' => $transferPayment->id, 'amount' => $transferPayment->amount, 'refund_method' => 'manual_transfer'],
            ],
            reason: 'Pengembalian seluruh pembayaran split',
            shift: $this->shift
        );

        $this->assertEquals($total, $refundTrx->total_refunded_amount);
        $this->assertCount(2, $refundTrx->refunds);

        $order->refresh();
        $this->assertEquals(Order::PAYMENT_STATUS_REFUNDED, $order->payment_status);
    }

    // ---------------------------------------------------------------------------
    // 6. Refund exceeding remaining balance is rejected
    // ---------------------------------------------------------------------------

    public function test_refund_exceeding_remaining_balance_is_rejected(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/melebihi saldo yang dapat direfund/');

        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $payment->amount + 1, 'refund_method' => 'cash'],
            ],
            reason: 'Refund melebihi batas',
            shift: $this->shift
        );
    }

    // ---------------------------------------------------------------------------
    // 7. Cumulative refunds cannot exceed original amount paid
    // ---------------------------------------------------------------------------

    public function test_cumulative_refunds_cannot_exceed_total_paid(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);
        $partialAmount = (int) floor($payment->amount / 2);

        // First partial refund
        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $partialAmount, 'refund_method' => 'cash'],
            ],
            reason: 'Refund sebagian pertama',
            shift: $this->shift
        );

        // Second refund exceeding remaining
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/melebihi saldo yang dapat direfund/');

        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $payment->amount, 'refund_method' => 'cash'],
            ],
            reason: 'Refund melebihi batas kumulatif',
            shift: $this->shift
        );
    }

    // ---------------------------------------------------------------------------
    // 8. Duplicate allocation entries (same payment_id, same method) are combined
    // ---------------------------------------------------------------------------

    public function test_duplicate_allocation_entries_for_same_payment_are_combined(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);

        // Two allocations targeting the same payment_id — should be combined
        $halfAmount = (int) floor($payment->amount / 2);

        $refundTrx = $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $halfAmount, 'refund_method' => 'cash'],
                ['payment_id' => $payment->id, 'amount' => $halfAmount, 'refund_method' => 'cash'],
            ],
            reason: 'Alokasi duplikat dikombinasikan',
            shift: $this->shift
        );

        // Should result in one PaymentRefund record with combined amount
        $this->assertCount(1, $refundTrx->refunds);
        $this->assertEquals($halfAmount * 2, $refundTrx->refunds->first()->amount);
    }

    // ---------------------------------------------------------------------------
    // 9. Payment belonging to another order is rejected
    // ---------------------------------------------------------------------------

    public function test_payment_belonging_to_another_order_is_rejected(): void
    {
        $order1 = $this->createPaidOrder();
        $order2 = $this->createPaidOrder();

        $payment2 = $this->getFirstPayment($order2);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/bukan milik pesanan ini/');

        // Try to refund order2's payment against order1
        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order1,
            allocations: [
                ['payment_id' => $payment2->id, 'amount' => $payment2->amount, 'refund_method' => 'cash'],
            ],
            reason: 'Silang pesanan',
            shift: $this->shift
        );
    }

    // ---------------------------------------------------------------------------
    // 10. Unauthorized cashier attempting a refund is rejected
    // ---------------------------------------------------------------------------

    public function test_cashier_cannot_process_refund(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Hanya Pemilik \(Owner\)/');

        $this->refundService->processRefund(
            authorizer: $this->cashier,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $payment->amount, 'refund_method' => 'cash'],
            ],
            reason: 'Kasir mencoba refund',
            shift: $this->shift
        );
    }

    // ---------------------------------------------------------------------------
    // 11. Missing active shift for cash refund is rejected
    // ---------------------------------------------------------------------------

    public function test_cash_refund_without_active_shift_is_rejected(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/memerlukan shift kasir yang sedang aktif/');

        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $payment->amount, 'refund_method' => 'cash'],
            ],
            reason: 'Refund tunai tanpa shift',
            shift: null
        );
    }

    // ---------------------------------------------------------------------------
    // 12. Non-cash refund succeeds without an active shift (confirmed above, also test explicitly)
    // ---------------------------------------------------------------------------

    public function test_non_cash_refund_does_not_require_shift(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);

        // Close the shift to prove non-cash doesn't need it
        app(ShiftService::class)->closeShift(
            shift: $this->shift,
            closingUser: $this->owner,
            actualCash: 0,
            closingNote: 'Shift ditutup untuk test'
        );

        $refundTrx = $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $payment->amount, 'refund_method' => 'manual_transfer'],
            ],
            reason: 'Transfer refund tanpa shift aktif',
            shift: null
        );

        $this->assertInstanceOf(RefundTransaction::class, $refundTrx);
        $order->refresh();
        $this->assertEquals(Order::PAYMENT_STATUS_REFUNDED, $order->payment_status);
    }

    // ---------------------------------------------------------------------------
    // 13. Identical idempotent retry returns original RefundTransaction
    // ---------------------------------------------------------------------------

    public function test_identical_idempotent_retry_returns_original_refund_transaction(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);
        $idempotencyKey = (string) Str::uuid();

        $firstResult = $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $payment->amount, 'refund_method' => 'cash'],
            ],
            reason: 'Retry idempotensi',
            shift: $this->shift,
            idempotencyKey: $idempotencyKey
        );

        // Retry with identical key — should return same record
        $secondResult = $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $payment->amount, 'refund_method' => 'cash'],
            ],
            reason: 'Retry idempotensi',
            shift: $this->shift,
            idempotencyKey: $idempotencyKey
        );

        $this->assertEquals($firstResult->id, $secondResult->id);
        $this->assertEquals($firstResult->refund_transaction_number, $secondResult->refund_transaction_number);

        // Only one PaymentRefund row should exist
        $this->assertEquals(1, PaymentRefund::where('order_id', $order->id)->count());
    }

    // ---------------------------------------------------------------------------
    // 14. Mismatched idempotency fingerprint throws HTTP 409
    // ---------------------------------------------------------------------------

    public function test_mismatched_idempotency_key_fingerprint_throws_conflict(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);
        $idempotencyKey = (string) Str::uuid();

        // First request: partial refund
        $partialAmount = (int) floor($payment->amount / 2);
        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $partialAmount, 'refund_method' => 'cash'],
            ],
            reason: 'Refund sebagian',
            shift: $this->shift,
            idempotencyKey: $idempotencyKey
        );

        // Second request: different amount with same key
        $this->expectException(ConflictHttpException::class);

        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $partialAmount + 1_000, 'refund_method' => 'cash'],
            ],
            reason: 'Refund berbeda fingerprint',
            shift: $this->shift,
            idempotencyKey: $idempotencyKey
        );
    }

    // ---------------------------------------------------------------------------
    // 15. Completed order remains completed after full refund
    // ---------------------------------------------------------------------------

    public function test_completed_order_remains_completed_after_full_refund(): void
    {
        $order = $this->createPaidOrder();

        // Advance order to completed
        $this->orderService->advanceStatus($order, Order::STATUS_QUEUED, $this->cashier);
        $this->orderService->advanceStatus($order->fresh(), Order::STATUS_PREPARING, $this->cashier);
        $this->orderService->advanceStatus($order->fresh(), Order::STATUS_READY, $this->cashier);
        $this->orderService->advanceStatus($order->fresh(), Order::STATUS_COMPLETED, $this->cashier);

        $order->refresh();
        $this->assertEquals(Order::STATUS_COMPLETED, $order->status);

        $payment = $this->getFirstPayment($order);

        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $payment->amount, 'refund_method' => 'cash'],
            ],
            reason: 'Pengembalian setelah pesanan selesai',
            shift: $this->shift
        );

        $order->refresh();
        // Status must remain completed
        $this->assertEquals(Order::STATUS_COMPLETED, $order->status);
        // Payment status must become refunded
        $this->assertEquals(Order::PAYMENT_STATUS_REFUNDED, $order->payment_status);
    }

    // ---------------------------------------------------------------------------
    // 16. Fully refunded non-completed order is cancelled with history
    // ---------------------------------------------------------------------------

    public function test_fully_refunded_non_completed_order_is_cancelled_with_history(): void
    {
        $order = $this->createPaidOrder();
        $this->assertEquals(Order::STATUS_PAID, $order->status);

        $payment = $this->getFirstPayment($order);

        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $payment->amount, 'refund_method' => 'cash'],
            ],
            reason: 'Pengembalian penuh pesanan belum selesai',
            shift: $this->shift
        );

        $order->refresh();
        $this->assertEquals(Order::STATUS_CANCELLED, $order->status);
        $this->assertEquals(Order::PAYMENT_STATUS_REFUNDED, $order->payment_status);
        $this->assertEquals($this->owner->id, $order->cancelled_by_user_id);
        $this->assertNotNull($order->cancelled_at);

        // Status history should contain the cancellation (filter to order-status entries only)
        $history = $order->statusHistories
            ->filter(fn ($h) => $h->to_status === Order::STATUS_CANCELLED)
            ->last();
        $this->assertNotNull($history);
        $this->assertEquals(Order::STATUS_CANCELLED, $history->to_status);
    }

    // ---------------------------------------------------------------------------
    // 17. Correct payment_status transitions (unpaid → not refundable)
    // ---------------------------------------------------------------------------

    public function test_unpaid_order_cannot_be_refunded(): void
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();
        $order = $this->orderService->createOrder(
            cashier: $this->cashier,
            shift: $this->shift,
            orderPayload: [
                'order_type' => Order::TYPE_DINE_IN,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ]
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/belum dibayar/');

        // Pick a fake payment_id — it doesn't matter since it should fail on the pre-check
        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => 999, 'amount' => 1_000, 'refund_method' => 'cash'],
            ],
            reason: 'Pesanan belum dibayar',
            shift: $this->shift
        );
    }

    // ---------------------------------------------------------------------------
    // 18. Correct expected-cash calculation after cash refunds
    // ---------------------------------------------------------------------------

    public function test_cash_refund_reduces_expected_cash_in_shift(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);
        $cashAmount = $payment->amount;

        $expectedBefore = $this->shift->fresh()->currentExpectedCash();

        // Expected cash before = opening_cash + cash_payment = 100_000 + amount
        $this->assertEquals(100_000 + $cashAmount, $expectedBefore);

        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $cashAmount, 'refund_method' => 'cash'],
            ],
            reason: 'Pengembalian tunai',
            shift: $this->shift
        );

        $expectedAfter = $this->shift->fresh()->currentExpectedCash();
        $this->assertEquals($expectedBefore - $cashAmount, $expectedAfter);
        // Final = opening cash only (payment and refund cancel out)
        $this->assertEquals(100_000, $expectedAfter);
    }

    // ---------------------------------------------------------------------------
    // 19. Non-cash refund does NOT affect expected cash
    // ---------------------------------------------------------------------------

    public function test_non_cash_refund_does_not_affect_expected_cash(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);

        $expectedBefore = $this->shift->fresh()->currentExpectedCash();

        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $payment->amount, 'refund_method' => 'qris_reversal'],
            ],
            reason: 'QRIS reversal tidak mempengaruhi kas',
            shift: null
        );

        // Expected cash should not change since non-cash refund
        $expectedAfter = $this->shift->fresh()->currentExpectedCash();
        $this->assertEquals($expectedBefore, $expectedAfter);
    }

    // ---------------------------------------------------------------------------
    // 20. Rollback when refund processing fails mid-transaction
    // ---------------------------------------------------------------------------

    public function test_rollback_when_refund_processing_fails(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);

        // Count before
        $refundTrxCountBefore = RefundTransaction::count();
        $paymentRefundCountBefore = PaymentRefund::count();

        try {
            $this->refundService->processRefund(
                authorizer: $this->owner,
                order: $order,
                allocations: [
                    // This will fail: amount exceeds balance
                    ['payment_id' => $payment->id, 'amount' => $payment->amount + 1, 'refund_method' => 'cash'],
                ],
                reason: 'Harus gagal dan rollback',
                shift: $this->shift
            );
        } catch (InvalidArgumentException) {
            // Expected
        }

        // No RefundTransaction or PaymentRefund should have been created
        $this->assertEquals($refundTrxCountBefore, RefundTransaction::count());
        $this->assertEquals($paymentRefundCountBefore, PaymentRefund::count());

        // Order payment_status must be unchanged
        $order->refresh();
        $this->assertEquals(Order::PAYMENT_STATUS_PAID, $order->payment_status);
    }

    // ---------------------------------------------------------------------------
    // 21. Audit log is recorded for successful refund
    // ---------------------------------------------------------------------------

    public function test_audit_log_is_recorded_on_successful_refund(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);

        $before = ActivityLog::count();

        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $payment->amount, 'refund_method' => 'cash'],
            ],
            reason: 'Audit log test',
            shift: $this->shift
        );

        $this->assertGreaterThan($before, ActivityLog::count());

        $log = ActivityLog::where('action', 'refund.processed')->latest()->first();
        $this->assertNotNull($log);
        $this->assertEquals($this->owner->id, $log->user_id);
        $this->assertEquals('payments', $log->module);
        $this->assertStringContainsString('Audit log test', $log->description);
    }

    // ---------------------------------------------------------------------------
    // 22. Zero-amount allocation is rejected
    // ---------------------------------------------------------------------------

    public function test_zero_amount_allocation_is_rejected(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/amount harus berupa bilangan bulat Rupiah lebih besar dari 0/');

        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => 0, 'refund_method' => 'cash'],
            ],
            reason: 'Nol Rupiah',
            shift: $this->shift
        );
    }

    // ---------------------------------------------------------------------------
    // 23. Invalid refund method is rejected
    // ---------------------------------------------------------------------------

    public function test_invalid_refund_method_is_rejected(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/metode refund.*tidak didukung/');

        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $payment->amount, 'refund_method' => 'bitcoin'],
            ],
            reason: 'Metode tidak valid',
            shift: $this->shift
        );
    }

    // ---------------------------------------------------------------------------
    // 24. Missing reason is rejected
    // ---------------------------------------------------------------------------

    public function test_empty_reason_is_rejected(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Alasan refund wajib diisi/');

        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $payment->amount, 'refund_method' => 'cash'],
            ],
            reason: '   ',
            shift: $this->shift
        );
    }

    // ---------------------------------------------------------------------------
    // 25. Partial refund does NOT cancel the order
    // ---------------------------------------------------------------------------

    public function test_partial_refund_does_not_cancel_order(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);
        $partialAmount = (int) floor($payment->amount / 2);

        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $partialAmount, 'refund_method' => 'cash'],
            ],
            reason: 'Sebagian saja',
            shift: $this->shift
        );

        $order->refresh();
        $this->assertEquals(Order::STATUS_PAID, $order->status);
        $this->assertEquals(Order::PAYMENT_STATUS_PARTIALLY_REFUNDED, $order->payment_status);
    }

    // ---------------------------------------------------------------------------
    // 26. Daily sequence increments correctly for multiple refund transactions
    // ---------------------------------------------------------------------------

    public function test_daily_sequence_increments_for_multiple_refund_transactions(): void
    {
        $order1 = $this->createPaidOrder();
        $order2 = $this->createPaidOrder();

        $payment1 = $this->getFirstPayment($order1);
        $payment2 = $this->getFirstPayment($order2);

        $partialAmount1 = (int) floor($payment1->amount / 2);
        $partialAmount2 = (int) floor($payment2->amount / 2);

        $rfd1 = $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order1,
            allocations: [
                ['payment_id' => $payment1->id, 'amount' => $partialAmount1, 'refund_method' => 'cash'],
            ],
            reason: 'Refund pertama',
            shift: $this->shift
        );

        $rfd2 = $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order2,
            allocations: [
                ['payment_id' => $payment2->id, 'amount' => $partialAmount2, 'refund_method' => 'cash'],
            ],
            reason: 'Refund kedua',
            shift: $this->shift
        );

        $this->assertEquals(1, $rfd1->daily_sequence);
        $this->assertEquals(2, $rfd2->daily_sequence);
        $this->assertStringStartsWith('RFD-', $rfd1->refund_transaction_number);
        $this->assertStringStartsWith('RFD-', $rfd2->refund_transaction_number);
        $this->assertNotEquals($rfd1->refund_transaction_number, $rfd2->refund_transaction_number);
    }

    // ---------------------------------------------------------------------------
    // 27. Already-fully-refunded order is rejected on second full refund attempt
    // ---------------------------------------------------------------------------

    public function test_already_fully_refunded_order_is_rejected(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);

        // Full refund
        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $payment->amount, 'refund_method' => 'cash'],
            ],
            reason: 'Refund pertama',
            shift: $this->shift
        );

        $order->refresh();
        $this->assertEquals(Order::PAYMENT_STATUS_REFUNDED, $order->payment_status);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/sudah sepenuhnya direfund/');

        // Second attempt — should be rejected pre-flight
        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $payment->amount, 'refund_method' => 'cash'],
            ],
            reason: 'Refund kedua gagal',
            shift: $this->shift
        );
    }

    // ---------------------------------------------------------------------------
    // 28. Conflict: same payment_id, different methods in same allocation set
    // ---------------------------------------------------------------------------

    public function test_same_payment_id_with_different_refund_methods_is_rejected(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);
        $halfAmount = (int) floor($payment->amount / 2);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/metode refund yang berbeda/');

        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $halfAmount, 'refund_method' => 'cash'],
                ['payment_id' => $payment->id, 'amount' => $halfAmount, 'refund_method' => 'manual_transfer'],
            ],
            reason: 'Metode konflik untuk ID yang sama',
            shift: $this->shift
        );
    }

    // ---------------------------------------------------------------------------
    // 29. Cash refund with closed shift is rejected
    // ---------------------------------------------------------------------------

    public function test_cash_refund_with_closed_shift_is_rejected(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);

        // Close the shift
        app(ShiftService::class)->closeShift(
            shift: $this->shift,
            closingUser: $this->owner,
            actualCash: 0,
            closingNote: 'Shift ditutup'
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Shift kasir sudah ditutup|sedang aktif/');

        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $payment->amount, 'refund_method' => 'cash'],
            ],
            reason: 'Refund setelah shift tutup',
            shift: $this->shift->fresh()
        );
    }

    // ---------------------------------------------------------------------------
    // 30. Original payment record is never modified (immutability preserved)
    // ---------------------------------------------------------------------------

    public function test_original_payment_record_is_not_modified_after_refund(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);

        $originalAmount = $payment->amount;
        $originalMethod = $payment->payment_method;
        $originalUpdatedAt = $payment->updated_at;

        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $payment->amount, 'refund_method' => 'cash'],
            ],
            reason: 'Verifikasi data asli',
            shift: $this->shift
        );

        $payment->refresh();
        $this->assertEquals($originalAmount, $payment->amount);
        $this->assertEquals($originalMethod, $payment->payment_method);
        $this->assertEquals($originalUpdatedAt->toIso8601String(), $payment->updated_at->toIso8601String());
    }

    // ---------------------------------------------------------------------------
    // 31. original_payment_method is preserved on PaymentRefund record
    // ---------------------------------------------------------------------------

    public function test_original_payment_method_preserved_on_refund_record(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);

        $this->assertEquals('cash', $payment->payment_method);

        $refundTrx = $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $payment->amount, 'refund_method' => 'manual_transfer'],
            ],
            reason: 'Metode refund berbeda dari metode asli',
            shift: null
        );

        $refund = PaymentRefund::where('refund_transaction_id', $refundTrx->id)->firstOrFail();
        $this->assertEquals('cash', $refund->original_payment_method);       // Original preserved
        $this->assertEquals('manual_transfer', $refund->refund_method);       // Refund method is different
    }

    // ---------------------------------------------------------------------------
    // 32. Empty allocations array is rejected
    // ---------------------------------------------------------------------------

    public function test_empty_allocations_array_is_rejected(): void
    {
        $order = $this->createPaidOrder();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/tidak boleh kosong/');

        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [],
            reason: 'Alokasi kosong',
            shift: $this->shift
        );
    }

    // ---------------------------------------------------------------------------
    // 33. Already-cancelled order cannot be re-cancelled by refund engine
    // ---------------------------------------------------------------------------

    public function test_already_cancelled_order_cannot_be_re_cancelled(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);

        // Pre-cancel order directly at DB level (simulating manual operational edge case)
        DB::table('orders')->where('id', $order->id)->update([
            'status' => Order::STATUS_CANCELLED,
            'cancellation_reason' => 'Prior manual cancellation',
            'cancelled_by_user_id' => $this->owner->id,
            'cancelled_at' => now(),
            'updated_at' => now(),
        ]);

        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order->fresh(),
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $payment->amount, 'refund_method' => 'manual_transfer'],
            ],
            reason: 'Refund for already cancelled order',
            shift: null
        );

        $order->refresh();
        $this->assertEquals(Order::PAYMENT_STATUS_REFUNDED, $order->payment_status);
        $this->assertEquals(Order::STATUS_CANCELLED, $order->status);
        $this->assertEquals('Prior manual cancellation', $order->cancellation_reason);
    }

    // ---------------------------------------------------------------------------
    // 34. Idempotency retry does not create duplicate child allocations
    // ---------------------------------------------------------------------------

    public function test_idempotent_retry_does_not_create_additional_child_refunds(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);
        $idempotencyKey = (string) Str::uuid();

        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $payment->amount, 'refund_method' => 'cash'],
            ],
            reason: 'Idempotency child count test',
            shift: $this->shift,
            idempotencyKey: $idempotencyKey
        );

        $refundTrxCount = RefundTransaction::count();
        $paymentRefundCount = PaymentRefund::count();

        // Retry with same idempotency key and identical fingerprint
        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $payment->amount, 'refund_method' => 'cash'],
            ],
            reason: 'Idempotency child count test',
            shift: $this->shift,
            idempotencyKey: $idempotencyKey
        );

        $this->assertEquals($refundTrxCount, RefundTransaction::count());
        $this->assertEquals($paymentRefundCount, PaymentRefund::count());
    }

    // ---------------------------------------------------------------------------
    // 35. Parent total_refunded_amount equals sum of child allocations
    // ---------------------------------------------------------------------------

    public function test_parent_total_refunded_amount_equals_sum_of_child_allocations(): void
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();

        $order = $this->orderService->createOrder(
            cashier: $this->cashier,
            shift: $this->shift,
            orderPayload: [
                'order_type' => Order::TYPE_DINE_IN,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 2],
                ],
            ]
        );

        $total = $order->total_amount;
        $cashPart = (int) floor($total / 2);
        $transferPart = $total - $cashPart;

        $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order,
            tenders: [
                [
                    'payment_method' => 'cash',
                    'amount' => $cashPart,
                    'tendered_amount' => $cashPart,
                    'change_amount' => 0,
                ],
                [
                    'payment_method' => 'transfer',
                    'amount' => $transferPart,
                    'tendered_amount' => $transferPart,
                ],
            ]
        );

        $order->refresh();
        $cashPayment = Payment::where('order_id', $order->id)->where('payment_method', 'cash')->firstOrFail();
        $transferPayment = Payment::where('order_id', $order->id)->where('payment_method', 'transfer')->firstOrFail();

        $partialCash = (int) floor($cashPart / 2);
        $partialTransfer = (int) floor($transferPart / 3);

        $refundTrx = $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $cashPayment->id, 'amount' => $partialCash, 'refund_method' => 'cash'],
                ['payment_id' => $transferPayment->id, 'amount' => $partialTransfer, 'refund_method' => 'manual_transfer'],
            ],
            reason: 'Partial refund two tenders',
            shift: $this->shift
        );

        $childSum = $refundTrx->refunds->sum('amount');
        $this->assertEquals($childSum, $refundTrx->total_refunded_amount);
        $this->assertEquals($partialCash + $partialTransfer, $refundTrx->total_refunded_amount);
    }

    // ---------------------------------------------------------------------------
    // 36. Unique constraint on idempotency_key prevents duplicate parent rows
    // ---------------------------------------------------------------------------

    public function test_unique_constraint_on_idempotency_key_prevents_duplicate_refund_transactions(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);
        $key = (string) Str::uuid();

        $fingerprint = $this->refundService->generateRefundRequestFingerprint(
            orderId: $order->id,
            authorizerUserId: $this->owner->id,
            totalRefundedAmount: $payment->amount,
            normalizedAllocations: [
                ['payment_id' => $payment->id, 'amount' => $payment->amount, 'refund_method' => 'manual_transfer'],
            ],
            reason: 'Unique constraint test'
        );

        // Simulate committed winner
        RefundTransaction::create([
            'ulid' => (string) Str::ulid(),
            'refund_transaction_number' => 'RFD-99990101-9999',
            'daily_sequence' => 9999,
            'business_date' => now()->toDateString(),
            'order_id' => $order->id,
            'authorized_by_user_id' => $this->owner->id,
            'total_refunded_amount' => $payment->amount,
            'reason' => 'Concurrent winner',
            'idempotency_key' => $key,
            'request_fingerprint' => $fingerprint,
        ]);

        $result = $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $payment->amount, 'refund_method' => 'manual_transfer'],
            ],
            reason: 'Unique constraint test',
            shift: null,
            idempotencyKey: $key
        );

        $this->assertEquals(1, RefundTransaction::where('idempotency_key', $key)->count());
        $this->assertEquals('Concurrent winner', $result->reason);
    }

    // ---------------------------------------------------------------------------
    // 37. Non-cash refund records intent only without claiming external completion
    // ---------------------------------------------------------------------------

    public function test_non_cash_refund_methods_record_intent_only_not_external_completion(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);

        $qrisRefundTrx = $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $payment->amount, 'refund_method' => 'qris_reversal'],
            ],
            reason: 'QRIS reversal recording',
            shift: null
        );

        $qrisRefund = PaymentRefund::where('refund_transaction_id', $qrisRefundTrx->id)->firstOrFail();
        $this->assertEquals('qris_reversal', $qrisRefund->refund_method);

        // Ensure schema has no external completion status column
        $this->assertFalse(
            Schema::hasColumn('payment_refunds', 'status'),
            'payment_refunds must not have an external status column'
        );

        $log = ActivityLog::where('action', 'refund.processed')->latest()->first();
        $this->assertNotNull($log);
        $this->assertArrayNotHasKey('qris_confirmed_externally', $log->properties);
        $this->assertArrayNotHasKey('bank_transfer_confirmed', $log->properties);
    }

    // ---------------------------------------------------------------------------
    // 38. Remaining balance aggregates across all refund payout methods
    // ---------------------------------------------------------------------------

    public function test_remaining_balance_aggregates_across_all_refund_methods(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);

        $firstAmount = (int) floor($payment->amount / 3);
        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $firstAmount, 'refund_method' => 'manual_transfer'],
            ],
            reason: 'First part via transfer',
            shift: null
        );

        $remaining = $payment->fresh()->remainingRefundableBalance();
        $this->assertEquals($payment->amount - $firstAmount, $remaining);

        $secondAmount = (int) floor($payment->amount / 3);
        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $secondAmount, 'refund_method' => 'cash'],
            ],
            reason: 'Second part via cash',
            shift: $this->shift
        );

        $remainingAfterTwo = $payment->fresh()->remainingRefundableBalance();
        $this->assertEquals($payment->amount - $firstAmount - $secondAmount, $remainingAfterTwo);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/melebihi saldo yang dapat direfund/');

        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $remainingAfterTwo + 1, 'refund_method' => 'manual_transfer'],
            ],
            reason: 'Exceeding remaining balance after multi-method refunds',
            shift: null
        );
    }

    // ---------------------------------------------------------------------------
    // 39. Canonical allocation: duplicate lines produce identical fingerprint as single combined
    // ---------------------------------------------------------------------------

    public function test_duplicate_allocation_lines_and_single_combined_allocation_produce_same_fingerprint(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);

        // Payload A: 100 + 200 on same payment and method
        $rawA = [
            ['payment_id' => $payment->id, 'amount' => 100, 'refund_method' => 'cash'],
            ['payment_id' => $payment->id, 'amount' => 200, 'refund_method' => 'cash'],
        ];
        $canonicalA = $this->refundService->normalizeAllocations($rawA);
        $fingerprintA = $this->refundService->generateRefundRequestFingerprint(
            orderId: $order->id,
            authorizerUserId: $this->owner->id,
            totalRefundedAmount: 300,
            normalizedAllocations: $canonicalA,
            reason: 'Canonical fingerprint test'
        );

        // Payload B: 300 on same payment and method
        $rawB = [
            ['payment_id' => $payment->id, 'amount' => 300, 'refund_method' => 'cash'],
        ];
        $canonicalB = $this->refundService->normalizeAllocations($rawB);
        $fingerprintB = $this->refundService->generateRefundRequestFingerprint(
            orderId: $order->id,
            authorizerUserId: $this->owner->id,
            totalRefundedAmount: 300,
            normalizedAllocations: $canonicalB,
            reason: 'Canonical fingerprint test'
        );

        $this->assertEquals($canonicalA, $canonicalB);
        $this->assertEquals($fingerprintA, $fingerprintB);
    }

    // ---------------------------------------------------------------------------
    // 40. Equivalent allocation payloads with same idempotency key return original transaction
    // ---------------------------------------------------------------------------

    public function test_equivalent_allocation_payloads_with_same_idempotency_key_return_original_transaction(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);
        $idempotencyKey = (string) Str::uuid();

        // First call: 100 + 200
        $result1 = $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => 100, 'refund_method' => 'cash'],
                ['payment_id' => $payment->id, 'amount' => 200, 'refund_method' => 'cash'],
            ],
            reason: 'Equivalent payload test',
            shift: $this->shift,
            idempotencyKey: $idempotencyKey
        );

        // Second call: 300 with same key
        $result2 = $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => 300, 'refund_method' => 'cash'],
            ],
            reason: 'Equivalent payload test',
            shift: $this->shift,
            idempotencyKey: $idempotencyKey
        );

        $this->assertEquals($result1->id, $result2->id);
        $this->assertEquals($result1->refund_transaction_number, $result2->refund_transaction_number);
        $this->assertEquals(300, $result2->total_refunded_amount);
        $this->assertCount(1, $result2->refunds);
    }

    // ---------------------------------------------------------------------------
    // 41. Conflicting refund methods for same payment are rejected in normalization
    // ---------------------------------------------------------------------------

    public function test_conflicting_refund_methods_for_same_payment_are_rejected_in_normalization(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/memiliki metode refund yang berbeda/');

        $this->refundService->normalizeAllocations([
            ['payment_id' => $payment->id, 'amount' => 100, 'refund_method' => 'cash'],
            ['payment_id' => $payment->id, 'amount' => 200, 'refund_method' => 'manual_transfer'],
        ]);
    }

    // ---------------------------------------------------------------------------
    // 42. Exact SQLite idempotency UNIQUE failure is recognized and recovers winner
    // ---------------------------------------------------------------------------

    public function test_exact_sqlite_idempotency_unique_failure_is_recognized_and_recovers_winner(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);
        $idempotencyKey = (string) Str::uuid();

        // 1. Thread A commits winning transaction
        $firstResult = $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => 100, 'refund_method' => 'cash'],
            ],
            reason: 'Collision recovery test',
            shift: $this->shift,
            idempotencyKey: $idempotencyKey
        );

        // 2. Thread B simulates concurrent race: passes pre-flight check, then encounters SQLite UNIQUE collision
        $racingService = new class extends RefundService
        {
            public int $lookupCount = 0;

            public function findExistingRefundTransaction(string $idempotencyKey): ?RefundTransaction
            {
                $this->lookupCount++;
                if ($this->lookupCount === 1) {
                    return null; // Pre-flight check sees null during simultaneous arrival
                }

                return parent::findExistingRefundTransaction($idempotencyKey);
            }
        };

        RefundTransaction::saving(function ($model) use ($idempotencyKey) {
            if ($model->idempotency_key === $idempotencyKey) {
                $msg = 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: refund_transactions.idempotency_key';
                $pdoEx = new \PDOException($msg, 23000);
                $pdoEx->errorInfo = ['23000', 19, 'UNIQUE constraint failed: refund_transactions.idempotency_key'];

                throw new QueryException('sqlite', 'insert into refund_transactions ...', [], $pdoEx);
            }
        });

        try {
            $secondResult = $racingService->processRefund(
                authorizer: $this->owner,
                order: $order,
                allocations: [
                    ['payment_id' => $payment->id, 'amount' => 100, 'refund_method' => 'cash'],
                ],
                reason: 'Collision recovery test',
                shift: $this->shift,
                idempotencyKey: $idempotencyKey
            );

            $this->assertEquals($firstResult->id, $secondResult->id);
            $this->assertEquals($firstResult->refund_transaction_number, $secondResult->refund_transaction_number);
        } finally {
            RefundTransaction::flushEventListeners();
        }
    }

    // ---------------------------------------------------------------------------
    // 43. Simulated MySQL idempotency duplicate-entry error is recognized and recovers winner
    // ---------------------------------------------------------------------------

    public function test_simulated_mysql_idempotency_duplicate_key_failure_is_recognized(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);
        $idempotencyKey = (string) Str::uuid();

        // 1. Thread A commits winning transaction
        $firstResult = $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => 100, 'refund_method' => 'cash'],
            ],
            reason: 'MySQL collision test',
            shift: $this->shift,
            idempotencyKey: $idempotencyKey
        );

        // 2. Thread B simulates concurrent race: passes pre-flight check, then encounters MySQL 1062 duplicate key
        $racingService = new class extends RefundService
        {
            public int $lookupCount = 0;

            public function findExistingRefundTransaction(string $idempotencyKey): ?RefundTransaction
            {
                $this->lookupCount++;
                if ($this->lookupCount === 1) {
                    return null; // Pre-flight check sees null during simultaneous arrival
                }

                return parent::findExistingRefundTransaction($idempotencyKey);
            }
        };

        RefundTransaction::saving(function ($model) use ($idempotencyKey) {
            if ($model->idempotency_key === $idempotencyKey) {
                $msg = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '{$idempotencyKey}' for key 'refund_transactions.refund_transactions_idempotency_key_unique'";
                $pdoEx = new \PDOException($msg, 23000);
                $pdoEx->errorInfo = ['23000', 1062, $msg];

                throw new QueryException('mysql', 'insert into refund_transactions ...', [], $pdoEx);
            }
        });

        try {
            $secondResult = $racingService->processRefund(
                authorizer: $this->owner,
                order: $order,
                allocations: [
                    ['payment_id' => $payment->id, 'amount' => 100, 'refund_method' => 'cash'],
                ],
                reason: 'MySQL collision test',
                shift: $this->shift,
                idempotencyKey: $idempotencyKey
            );

            $this->assertEquals($firstResult->id, $secondResult->id);
            $this->assertEquals($firstResult->refund_transaction_number, $secondResult->refund_transaction_number);
        } finally {
            RefundTransaction::flushEventListeners();
        }
    }

    // ---------------------------------------------------------------------------
    // 44. Collision with a different fingerprint throws HTTP 409
    // ---------------------------------------------------------------------------

    public function test_collision_with_different_fingerprint_throws_conflict(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);
        $idempotencyKey = (string) Str::uuid();

        // Pre-commit winning transaction with amount 100
        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => 100, 'refund_method' => 'cash'],
            ],
            reason: 'Collision conflict test',
            shift: $this->shift,
            idempotencyKey: $idempotencyKey
        );

        $racingService = new class extends RefundService
        {
            public int $lookupCount = 0;

            public function findExistingRefundTransaction(string $idempotencyKey): ?RefundTransaction
            {
                $this->lookupCount++;
                if ($this->lookupCount === 1) {
                    return null; // Pre-flight check sees null during simultaneous arrival
                }

                return parent::findExistingRefundTransaction($idempotencyKey);
            }
        };

        // Hook to simulate losing thread encountering unique collision with different payload (amount 200)
        RefundTransaction::saving(function ($model) use ($idempotencyKey) {
            if ($model->idempotency_key === $idempotencyKey) {
                $msg = 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: refund_transactions.idempotency_key';
                $pdoEx = new \PDOException($msg, 23000);
                $pdoEx->errorInfo = ['23000', 19, 'UNIQUE constraint failed: refund_transactions.idempotency_key'];

                throw new QueryException('sqlite', 'insert into refund_transactions ...', [], $pdoEx);
            }
        });

        try {
            $this->expectException(ConflictHttpException::class);

            // Payload differs: amount is 200 instead of 100
            $racingService->processRefund(
                authorizer: $this->owner,
                order: $order,
                allocations: [
                    ['payment_id' => $payment->id, 'amount' => 200, 'refund_method' => 'cash'],
                ],
                reason: 'Collision conflict test',
                shift: $this->shift,
                idempotencyKey: $idempotencyKey
            );
        } finally {
            RefundTransaction::flushEventListeners();
        }
    }

    // ---------------------------------------------------------------------------
    // 45. SQLite foreign-key, NOT NULL, and CHECK violations are not treated as idempotency collisions
    // ---------------------------------------------------------------------------

    public function test_sqlite_foreign_key_violation_is_not_treated_as_idempotency_collision(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);
        $idempotencyKey = (string) Str::uuid();

        // Pre-commit winning transaction so an existing transaction exists in DB
        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => 100, 'refund_method' => 'cash'],
            ],
            reason: 'Pre-existing winner',
            shift: $this->shift,
            idempotencyKey: $idempotencyKey
        );

        $racingService = new class extends RefundService
        {
            public int $lookupCount = 0;

            public function findExistingRefundTransaction(string $idempotencyKey): ?RefundTransaction
            {
                $this->lookupCount++;
                if ($this->lookupCount === 1) {
                    return null; // Pre-flight check sees null during simultaneous arrival
                }

                return parent::findExistingRefundTransaction($idempotencyKey);
            }
        };

        RefundTransaction::saving(function () {
            $pdoEx = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 FOREIGN KEY constraint failed', 23000);
            $pdoEx->errorInfo = ['23000', 19, 'FOREIGN KEY constraint failed'];

            throw new QueryException('sqlite', 'insert into refund_transactions ...', [], $pdoEx);
        });

        try {
            $this->expectException(QueryException::class);

            $racingService->processRefund(
                authorizer: $this->owner,
                order: $order,
                allocations: [
                    ['payment_id' => $payment->id, 'amount' => 100, 'refund_method' => 'cash'],
                ],
                reason: 'Pre-existing winner',
                shift: $this->shift,
                idempotencyKey: $idempotencyKey
            );
        } finally {
            RefundTransaction::flushEventListeners();
        }
    }

    public function test_sqlite_not_null_violation_is_not_treated_as_idempotency_collision(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);
        $idempotencyKey = (string) Str::uuid();

        // Pre-commit winning transaction
        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => 100, 'refund_method' => 'cash'],
            ],
            reason: 'Pre-existing winner',
            shift: $this->shift,
            idempotencyKey: $idempotencyKey
        );

        $racingService = new class extends RefundService
        {
            public int $lookupCount = 0;

            public function findExistingRefundTransaction(string $idempotencyKey): ?RefundTransaction
            {
                $this->lookupCount++;
                if ($this->lookupCount === 1) {
                    return null; // Pre-flight check sees null during simultaneous arrival
                }

                return parent::findExistingRefundTransaction($idempotencyKey);
            }
        };

        RefundTransaction::saving(function () {
            $pdoEx = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed: refund_transactions.idempotency_key', 23000);
            $pdoEx->errorInfo = ['23000', 19, 'NOT NULL constraint failed: refund_transactions.idempotency_key'];

            throw new QueryException('sqlite', 'insert into refund_transactions ...', [], $pdoEx);
        });

        try {
            $this->expectException(QueryException::class);

            $racingService->processRefund(
                authorizer: $this->owner,
                order: $order,
                allocations: [
                    ['payment_id' => $payment->id, 'amount' => 100, 'refund_method' => 'cash'],
                ],
                reason: 'Pre-existing winner',
                shift: $this->shift,
                idempotencyKey: $idempotencyKey
            );
        } finally {
            RefundTransaction::flushEventListeners();
        }
    }

    public function test_sqlite_check_constraint_violation_is_not_treated_as_idempotency_collision(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);
        $idempotencyKey = (string) Str::uuid();

        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => 100, 'refund_method' => 'cash'],
            ],
            reason: 'Pre-existing winner',
            shift: $this->shift,
            idempotencyKey: $idempotencyKey
        );

        $racingService = new class extends RefundService
        {
            public int $lookupCount = 0;

            public function findExistingRefundTransaction(string $idempotencyKey): ?RefundTransaction
            {
                $this->lookupCount++;
                if ($this->lookupCount === 1) {
                    return null; // Pre-flight check sees null during simultaneous arrival
                }

                return parent::findExistingRefundTransaction($idempotencyKey);
            }
        };

        RefundTransaction::saving(function () {
            $pdoEx = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 CHECK constraint failed: refund_transactions', 23000);
            $pdoEx->errorInfo = ['23000', 19, 'CHECK constraint failed: refund_transactions'];

            throw new QueryException('sqlite', 'insert into refund_transactions ...', [], $pdoEx);
        });

        try {
            $this->expectException(QueryException::class);

            $racingService->processRefund(
                authorizer: $this->owner,
                order: $order,
                allocations: [
                    ['payment_id' => $payment->id, 'amount' => 100, 'refund_method' => 'cash'],
                ],
                reason: 'Pre-existing winner',
                shift: $this->shift,
                idempotencyKey: $idempotencyKey
            );
        } finally {
            RefundTransaction::flushEventListeners();
        }
    }

    // ---------------------------------------------------------------------------
    // 46. Duplicate-key error for another unique index is not treated as idempotency collision
    // ---------------------------------------------------------------------------

    public function test_duplicate_key_error_for_another_unique_index_is_not_treated_as_idempotency_collision(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);
        $idempotencyKey = (string) Str::uuid();

        // Pre-commit winning transaction so idempotency_key is present in DB
        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => 100, 'refund_method' => 'cash'],
            ],
            reason: 'Pre-existing winner',
            shift: $this->shift,
            idempotencyKey: $idempotencyKey
        );

        $racingService = new class extends RefundService
        {
            public int $lookupCount = 0;

            public function findExistingRefundTransaction(string $idempotencyKey): ?RefundTransaction
            {
                $this->lookupCount++;
                if ($this->lookupCount === 1) {
                    return null; // Pre-flight check sees null during simultaneous arrival
                }

                return parent::findExistingRefundTransaction($idempotencyKey);
            }
        };

        // 1. Test SQLite collision on ulid
        RefundTransaction::saving(function () {
            $pdoEx = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: refund_transactions.ulid', 23000);
            $pdoEx->errorInfo = ['23000', 19, 'UNIQUE constraint failed: refund_transactions.ulid'];

            throw new QueryException('sqlite', 'insert into refund_transactions ...', [], $pdoEx);
        });

        try {
            $threwSqlite = false;
            try {
                $racingService->processRefund(
                    authorizer: $this->owner,
                    order: $order,
                    allocations: [
                        ['payment_id' => $payment->id, 'amount' => 100, 'refund_method' => 'cash'],
                    ],
                    reason: 'Pre-existing winner',
                    shift: $this->shift,
                    idempotencyKey: $idempotencyKey
                );
            } catch (QueryException) {
                $threwSqlite = true;
            }
            $this->assertTrue($threwSqlite, 'SQLite duplicate ulid must throw QueryException instead of recovering.');
        } finally {
            RefundTransaction::flushEventListeners();
        }

        // 2. Test MySQL collision on uniq_ref_trx_biz_date_seq
        $racingServiceMysql = new class extends RefundService
        {
            public int $lookupCount = 0;

            public function findExistingRefundTransaction(string $idempotencyKey): ?RefundTransaction
            {
                $this->lookupCount++;
                if ($this->lookupCount === 1) {
                    return null; // Pre-flight check sees null during simultaneous arrival
                }

                return parent::findExistingRefundTransaction($idempotencyKey);
            }
        };

        RefundTransaction::saving(function () {
            $msg = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '2026-11-14-1' for key 'refund_transactions.uniq_ref_trx_biz_date_seq'";
            $pdoEx = new \PDOException($msg, 23000);
            $pdoEx->errorInfo = ['23000', 1062, $msg];

            throw new QueryException('mysql', 'insert into refund_transactions ...', [], $pdoEx);
        });

        try {
            $threwMysql = false;
            try {
                $racingServiceMysql->processRefund(
                    authorizer: $this->owner,
                    order: $order,
                    allocations: [
                        ['payment_id' => $payment->id, 'amount' => 100, 'refund_method' => 'cash'],
                    ],
                    reason: 'Pre-existing winner',
                    shift: $this->shift,
                    idempotencyKey: $idempotencyKey
                );
            } catch (QueryException) {
                $threwMysql = true;
            }
            $this->assertTrue($threwMysql, 'MySQL duplicate sequence must throw QueryException instead of recovering.');
        } finally {
            RefundTransaction::flushEventListeners();
        }
    }

    // ---------------------------------------------------------------------------
    // 47. Failed collision attempt leaves no extra parent or child refund records
    // ---------------------------------------------------------------------------

    public function test_failed_collision_attempt_leaves_no_extra_records(): void
    {
        $order = $this->createPaidOrder();
        $payment = $this->getFirstPayment($order);
        $idempotencyKey = (string) Str::uuid();

        // Normal first transaction
        $this->refundService->processRefund(
            authorizer: $this->owner,
            order: $order,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => 100, 'refund_method' => 'cash'],
            ],
            reason: 'Clean state test',
            shift: $this->shift,
            idempotencyKey: $idempotencyKey
        );

        $initialParentCount = RefundTransaction::count();
        $initialChildCount = PaymentRefund::count();

        $racingService = new class extends RefundService
        {
            public int $lookupCount = 0;

            public function findExistingRefundTransaction(string $idempotencyKey): ?RefundTransaction
            {
                $this->lookupCount++;
                if ($this->lookupCount === 1) {
                    return null; // Pre-flight check sees null during simultaneous arrival
                }

                return parent::findExistingRefundTransaction($idempotencyKey);
            }
        };

        // Hook to simulate failure on idempotency_key
        RefundTransaction::saving(function ($model) use ($idempotencyKey) {
            if ($model->idempotency_key === $idempotencyKey) {
                $msg = 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: refund_transactions.idempotency_key';
                $pdoEx = new \PDOException($msg, 23000);
                $pdoEx->errorInfo = ['23000', 19, 'UNIQUE constraint failed: refund_transactions.idempotency_key'];

                throw new QueryException('sqlite', 'insert into refund_transactions ...', [], $pdoEx);
            }
        });

        try {
            // This recovers existing transaction
            $racingService->processRefund(
                authorizer: $this->owner,
                order: $order,
                allocations: [
                    ['payment_id' => $payment->id, 'amount' => 100, 'refund_method' => 'cash'],
                ],
                reason: 'Clean state test',
                shift: $this->shift,
                idempotencyKey: $idempotencyKey
            );
        } finally {
            RefundTransaction::flushEventListeners();
        }

        // Counts must be strictly identical
        $this->assertEquals($initialParentCount, RefundTransaction::count());
        $this->assertEquals($initialChildCount, PaymentRefund::count());
    }
}
