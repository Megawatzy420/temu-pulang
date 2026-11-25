<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\Order\OrderIdempotencyConflictException;
use App\Exceptions\Order\ShiftClosedForOrderException;
use App\Exceptions\Payment\InsufficientCashTenderedException;
use App\Exceptions\Payment\OrderAlreadyPaidException;
use App\Exceptions\Payment\OrderCancelledForPaymentException;
use App\Exceptions\Payment\PaymentIdempotencyConflictException;
use App\Exceptions\Payment\QrisConfirmationExpiredOrUsedException;
use App\Exceptions\Payment\QrisConfirmationInvalidException;
use App\Exceptions\Payment\ShiftClosedForPaymentException;
use App\Exceptions\Refund\CashRefundRequiresOpenShiftException;
use App\Exceptions\Refund\OrderAlreadyRefundedException;
use App\Exceptions\Refund\RefundExceedsRemainingBalanceException;
use App\Exceptions\Refund\RefundIdempotencyConflictException;
use App\Exceptions\Refund\RefundReasonRequiredException;
use App\Exceptions\Refund\RefundTotalExceedsPaidAmountException;
use App\Exceptions\Refund\UnpaidOrderNotRefundableException;
use App\Livewire\Orders\OrderRefund;
use App\Livewire\Pos\CashierPos;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\RefundService;
use App\Services\ShiftService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;
use Throwable;

class DomainExceptionsMappingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    private Shift $openShift;

    private Shift $closedShift;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->owner = User::where('email', 'owner@temupulang.local')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();

        $this->openShift = app(ShiftService::class)->openShift(
            user: $this->cashier,
            openingCash: 100000,
            openingNote: 'Open shift for DomainExceptionsMappingTest'
        );

        $this->closedShift = Shift::create([
            'shift_number' => 'SHF-TEST-CLOSED-'.uniqid(),
            'user_id' => $this->cashier->id,
            'closed_by_user_id' => $this->cashier->id,
            'opened_at' => now('Asia/Jakarta')->subHours(5),
            'closed_at' => now('Asia/Jakarta')->subHours(2),
            'opening_cash' => 100000,
            'expected_cash' => 100000,
            'actual_cash' => 100000,
            'discrepancy' => 0,
            'status' => 'closed',
        ]);

        $this->product = Product::where('has_variants', false)
            ->where('is_sold_out', false)
            ->firstOrFail();
    }

    private function createUnpaidOrder(): Order
    {
        return app(OrderService::class)->createOrder($this->cashier, $this->openShift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ]);
    }

    private function createPaidOrder(): array
    {
        $order = $this->createUnpaidOrder();
        $total = $order->total_amount;

        $tx = app(PaymentService::class)->settlePayment(
            cashier: $this->cashier,
            shift: $this->openShift,
            order: $order,
            tenders: [
                [
                    'payment_method' => 'cash',
                    'amount' => $total,
                    'tendered_amount' => $total,
                    'change_amount' => 0,
                ],
            ],
            idempotencyKey: 'pay_'.Str::uuid()
        );

        return [$order->fresh(), $tx->payments()->firstOrFail()];
    }

    public function test_order_service_throws_shift_closed_for_order_exception(): void
    {
        $service = app(OrderService::class);

        try {
            $service->createOrder($this->cashier, $this->closedShift, [
                'order_type' => Order::TYPE_DINE_IN,
                'idempotency_key' => (string) Str::uuid(),
                'items' => [
                    ['product_id' => $this->product->id, 'quantity' => 1],
                ],
            ]);
            $this->fail('Expected ShiftClosedForOrderException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(ShiftClosedForOrderException::class, $e);
            $this->assertInstanceOf(InvalidArgumentException::class, $e);
            $this->assertSame('Shift kasir sudah ditutup. Tidak dapat memproses transaksi baru.', $e->getMessage());
        }
    }

    public function test_order_service_throws_order_idempotency_conflict_exception(): void
    {
        $service = app(OrderService::class);
        $sharedKey = 'shared-order-idem-'.Str::uuid();

        $service->createOrder($this->cashier, $this->openShift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => $sharedKey,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ]);

        try {
            $service->createOrder($this->cashier, $this->openShift, [
                'order_type' => Order::TYPE_TAKEAWAY,
                'idempotency_key' => $sharedKey,
                'items' => [
                    ['product_id' => $this->product->id, 'quantity' => 1],
                ],
            ]);
            $this->fail('Expected OrderIdempotencyConflictException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(OrderIdempotencyConflictException::class, $e);
            $this->assertInstanceOf(ConflictHttpException::class, $e);
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('Kunci idempotensi sudah digunakan untuk tipe pesanan berbeda.', $e->getMessage());
        }
    }

    public function test_payment_service_throws_shift_closed_for_payment_exception(): void
    {
        $service = app(PaymentService::class);
        $order = $this->createUnpaidOrder();

        try {
            $service->settlePayment(
                cashier: $this->cashier,
                shift: $this->closedShift,
                order: $order,
                tenders: [
                    [
                        'payment_method' => 'cash',
                        'amount' => $order->total_amount,
                        'tendered_amount' => $order->total_amount,
                    ],
                ],
                idempotencyKey: 'pay-closed-shift'
            );
            $this->fail('Expected ShiftClosedForPaymentException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(ShiftClosedForPaymentException::class, $e);
            $this->assertInstanceOf(InvalidArgumentException::class, $e);
            $this->assertSame('Shift kasir sudah ditutup. Tidak dapat memproses pembayaran.', $e->getMessage());
        }
    }

    public function test_payment_service_throws_insufficient_cash_tendered_exception(): void
    {
        $service = app(PaymentService::class);
        $order = $this->createUnpaidOrder();
        $total = $order->total_amount;
        $shortCash = $total - 1000;

        try {
            $service->settlePayment(
                cashier: $this->cashier,
                shift: $this->openShift,
                order: $order,
                tenders: [
                    [
                        'payment_method' => 'cash',
                        'amount' => $total,
                        'tendered_amount' => $shortCash,
                    ],
                ],
                idempotencyKey: 'pay-insufficient-cash'
            );
            $this->fail('Expected InsufficientCashTenderedException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(InsufficientCashTenderedException::class, $e);
            $this->assertInstanceOf(InvalidArgumentException::class, $e);
            $this->assertSame(
                'Uang tunai yang diterima (Rp '.number_format($shortCash, 0, ',', '.').') kurang dari nilai tagihan (Rp '.number_format($total, 0, ',', '.').').',
                $e->getMessage()
            );
        }
    }

    public function test_payment_service_throws_qris_confirmation_invalid_exception(): void
    {
        $service = app(PaymentService::class);
        $order = $this->createUnpaidOrder();

        try {
            $service->settlePayment(
                cashier: $this->cashier,
                shift: $this->openShift,
                order: $order,
                tenders: [
                    [
                        'payment_method' => 'qris',
                        'amount' => $order->total_amount,
                    ],
                ],
                idempotencyKey: 'pay-qris-invalid'
            );
            $this->fail('Expected QrisConfirmationInvalidException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(QrisConfirmationInvalidException::class, $e);
            $this->assertInstanceOf(InvalidArgumentException::class, $e);
            $this->assertSame('Pembayaran QRIS memerlukan konfirmasi server yang valid (qris_confirmation_id).', $e->getMessage());
        }
    }

    public function test_payment_service_throws_qris_confirmation_expired_or_used_exception(): void
    {
        $service = app(PaymentService::class);
        $order = $this->createUnpaidOrder();

        $confirmation = $service->createQrisConfirmation(
            cashier: $this->cashier,
            order: $order,
            expectedAmount: $order->total_amount,
            referenceNumber: 'QRIS-USED-001'
        );

        $confirmation->update([
            'status' => 'consumed',
            'consumed_at' => now('Asia/Jakarta'),
        ]);

        try {
            $service->settlePayment(
                cashier: $this->cashier,
                shift: $this->openShift,
                order: $order,
                tenders: [
                    [
                        'payment_method' => 'qris',
                        'amount' => $order->total_amount,
                        'qris_confirmation_id' => $confirmation->id,
                    ],
                ],
                idempotencyKey: 'pay-qris-used'
            );
            $this->fail('Expected QrisConfirmationExpiredOrUsedException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(QrisConfirmationExpiredOrUsedException::class, $e);
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertSame('Konfirmasi QRIS sudah pernah digunakan atau kadaluarsa.', $e->getMessage());
        }
    }

    public function test_payment_service_throws_order_already_paid_exception(): void
    {
        $service = app(PaymentService::class);
        [$paidOrder] = $this->createPaidOrder();

        try {
            $service->createQrisConfirmation(
                cashier: $this->cashier,
                order: $paidOrder,
                expectedAmount: $paidOrder->total_amount
            );
            $this->fail('Expected OrderAlreadyPaidException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(OrderAlreadyPaidException::class, $e);
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertSame('Pesanan ini sudah dibayar.', $e->getMessage());
        }
    }

    public function test_payment_service_throws_order_cancelled_for_payment_exception(): void
    {
        $service = app(PaymentService::class);
        $order = $this->createUnpaidOrder();
        app(OrderService::class)->cancelOrder($order, $this->owner, 'Batal uji');
        $order->refresh();

        try {
            $service->settlePayment(
                cashier: $this->cashier,
                shift: $this->openShift,
                order: $order,
                tenders: [
                    [
                        'payment_method' => 'cash',
                        'amount' => $order->total_amount,
                        'tendered_amount' => $order->total_amount,
                    ],
                ],
                idempotencyKey: 'pay-cancelled-order'
            );
            $this->fail('Expected OrderCancelledForPaymentException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(OrderCancelledForPaymentException::class, $e);
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertSame('Tidak dapat memproses pembayaran untuk pesanan yang dibatalkan.', $e->getMessage());
        }
    }

    public function test_payment_service_throws_payment_idempotency_conflict_exception(): void
    {
        $service = app(PaymentService::class);
        $order1 = $this->createUnpaidOrder();
        $order2 = $this->createUnpaidOrder();
        $sharedKey = 'shared-pay-idem-'.Str::uuid();

        $service->settlePayment(
            cashier: $this->cashier,
            shift: $this->openShift,
            order: $order1,
            tenders: [
                [
                    'payment_method' => 'cash',
                    'amount' => $order1->total_amount,
                    'tendered_amount' => $order1->total_amount,
                ],
            ],
            idempotencyKey: $sharedKey
        );

        try {
            $service->settlePayment(
                cashier: $this->cashier,
                shift: $this->openShift,
                order: $order2,
                tenders: [
                    [
                        'payment_method' => 'cash',
                        'amount' => $order2->total_amount,
                        'tendered_amount' => $order2->total_amount,
                    ],
                ],
                idempotencyKey: $sharedKey
            );
            $this->fail('Expected PaymentIdempotencyConflictException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(PaymentIdempotencyConflictException::class, $e);
            $this->assertInstanceOf(ConflictHttpException::class, $e);
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('Kunci idempotensi sudah digunakan dengan parameter pembayaran berbeda.', $e->getMessage());
        }
    }

    public function test_refund_service_throws_refund_reason_required_exception(): void
    {
        $service = app(RefundService::class);
        [$paidOrder, $payment] = $this->createPaidOrder();

        try {
            $service->processRefund(
                authorizer: $this->owner,
                order: $paidOrder,
                allocations: [
                    ['payment_id' => $payment->id, 'amount' => 5000, 'refund_method' => 'cash'],
                ],
                reason: '   ',
                shift: $this->openShift,
                idempotencyKey: 'refund-empty-reason'
            );
            $this->fail('Expected RefundReasonRequiredException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(RefundReasonRequiredException::class, $e);
            $this->assertInstanceOf(InvalidArgumentException::class, $e);
            $this->assertSame('Alasan refund wajib diisi.', $e->getMessage());
        }
    }

    public function test_refund_service_throws_unpaid_order_not_refundable_exception(): void
    {
        $service = app(RefundService::class);
        $unpaidOrder = $this->createUnpaidOrder();

        try {
            $service->processRefund(
                authorizer: $this->owner,
                order: $unpaidOrder,
                allocations: [
                    ['payment_id' => 999, 'amount' => 5000, 'refund_method' => 'cash'],
                ],
                reason: 'Salah input',
                shift: $this->openShift,
                idempotencyKey: 'refund-unpaid'
            );
            $this->fail('Expected UnpaidOrderNotRefundableException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(UnpaidOrderNotRefundableException::class, $e);
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertSame('Pesanan ini belum dibayar dan tidak dapat direfund.', $e->getMessage());
        }
    }

    public function test_refund_service_throws_order_already_refunded_exception(): void
    {
        $service = app(RefundService::class);
        [$paidOrder, $payment] = $this->createPaidOrder();

        $service->processRefund(
            authorizer: $this->owner,
            order: $paidOrder,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => $payment->amount, 'refund_method' => 'cash'],
            ],
            reason: 'Refund penuh pertama',
            shift: $this->openShift,
            idempotencyKey: 'refund-full-first'
        );

        $paidOrder->refresh();

        try {
            $service->processRefund(
                authorizer: $this->owner,
                order: $paidOrder,
                allocations: [
                    ['payment_id' => $payment->id, 'amount' => 1000, 'refund_method' => 'cash'],
                ],
                reason: 'Refund kedua',
                shift: $this->openShift,
                idempotencyKey: 'refund-full-second'
            );
            $this->fail('Expected OrderAlreadyRefundedException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(OrderAlreadyRefundedException::class, $e);
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertSame('Pesanan ini sudah sepenuhnya direfund.', $e->getMessage());
        }
    }

    public function test_refund_service_throws_cash_refund_requires_open_shift_exception(): void
    {
        $service = app(RefundService::class);
        [$paidOrder, $payment] = $this->createPaidOrder();

        try {
            $service->processRefund(
                authorizer: $this->owner,
                order: $paidOrder,
                allocations: [
                    ['payment_id' => $payment->id, 'amount' => 5000, 'refund_method' => 'cash'],
                ],
                reason: 'Salah input',
                shift: null,
                idempotencyKey: 'refund-no-shift'
            );
            $this->fail('Expected CashRefundRequiresOpenShiftException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(CashRefundRequiresOpenShiftException::class, $e);
            $this->assertInstanceOf(InvalidArgumentException::class, $e);
            $this->assertSame('Refund tunai memerlukan shift kasir yang sedang aktif (open).', $e->getMessage());
        }
    }

    public function test_refund_service_throws_refund_exceeds_remaining_balance_exception(): void
    {
        $service = app(RefundService::class);
        [$paidOrder, $payment] = $this->createPaidOrder();
        $overAmount = $payment->amount + 5000;

        try {
            $service->processRefund(
                authorizer: $this->owner,
                order: $paidOrder,
                allocations: [
                    ['payment_id' => $payment->id, 'amount' => $overAmount, 'refund_method' => 'cash'],
                ],
                reason: 'Salah input',
                shift: $this->openShift,
                idempotencyKey: 'refund-over-balance'
            );
            $this->fail('Expected RefundExceedsRemainingBalanceException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(RefundExceedsRemainingBalanceException::class, $e);
            $this->assertInstanceOf(InvalidArgumentException::class, $e);
            $this->assertSame(
                "Jumlah refund untuk pembayaran #{$payment->id} (Rp ".number_format($overAmount, 0, ',', '.').
                ') melebihi saldo yang dapat direfund (Rp '.number_format($payment->amount, 0, ',', '.').').
                        ',
                $e->getMessage()
            );
        }
    }

    public function test_refund_service_throws_refund_total_exceeds_paid_amount_exception(): void
    {
        $service = app(RefundService::class);
        [$paidOrder, $payment] = $this->createPaidOrder();

        // Insert a historical payment_refund row for a sibling payment id so per-payment remaining balance passes
        // while cumulative order refund exceeds total paid
        $dummyRefundTxId = DB::table('refund_transactions')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'refund_transaction_number' => 'RFD-TEST-0001',
            'business_date' => now('Asia/Jakarta')->toDateString(),
            'daily_sequence' => 999,
            'order_id' => $paidOrder->id,
            'authorized_by_user_id' => $this->owner->id,
            'total_refunded_amount' => 1000,
            'reason' => 'Prior partial',
            'idempotency_key' => 'prior-idem-'.Str::uuid(),
            'request_fingerprint' => str_repeat('a', 64),
            'created_at' => now('Asia/Jakarta'),
            'updated_at' => now('Asia/Jakarta'),
        ]);

        // Create an unlinked payment row id so $payment->remainingRefundableBalance() stays $payment->amount,
        // while PaymentRefund::where('order_id', $lockedOrder->id)->sum('amount') is 1000
        [$otherOrder, $otherPayment] = $this->createPaidOrder();
        $otherPaymentId = $otherPayment->id;

        DB::table('payment_refunds')->insert([
            'refund_transaction_id' => $dummyRefundTxId,
            'payment_id' => $otherPaymentId,
            'order_id' => $paidOrder->id,
            'shift_id' => $this->openShift->id,
            'original_payment_method' => 'cash',
            'refund_method' => 'cash',
            'amount' => 1000,
            'created_at' => now('Asia/Jakarta'),
            'updated_at' => now('Asia/Jakarta'),
        ]);

        try {
            $service->processRefund(
                authorizer: $this->owner,
                order: $paidOrder,
                allocations: [
                    ['payment_id' => $payment->id, 'amount' => $payment->amount, 'refund_method' => 'cash'],
                ],
                reason: 'Cumulative over refund',
                shift: $this->openShift,
                idempotencyKey: 'refund-cumulative-over'
            );
            $this->fail('Expected RefundTotalExceedsPaidAmountException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(RefundTotalExceedsPaidAmountException::class, $e);
            $this->assertInstanceOf(DomainException::class, $e);
            $this->assertSame('Total refund melebihi total pembayaran untuk pesanan ini.', $e->getMessage());
        }
    }

    public function test_refund_service_throws_refund_idempotency_conflict_exception(): void
    {
        $service = app(RefundService::class);
        [$paidOrder, $payment] = $this->createPaidOrder();
        $sharedKey = 'shared-refund-idem-'.Str::uuid();

        $service->processRefund(
            authorizer: $this->owner,
            order: $paidOrder,
            allocations: [
                ['payment_id' => $payment->id, 'amount' => 2000, 'refund_method' => 'cash'],
            ],
            reason: 'Salah input',
            shift: $this->openShift,
            idempotencyKey: $sharedKey
        );

        try {
            $service->processRefund(
                authorizer: $this->owner,
                order: $paidOrder,
                allocations: [
                    ['payment_id' => $payment->id, 'amount' => 3000, 'refund_method' => 'cash'],
                ],
                reason: 'Salah input',
                shift: $this->openShift,
                idempotencyKey: $sharedKey
            );
            $this->fail('Expected RefundIdempotencyConflictException was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(RefundIdempotencyConflictException::class, $e);
            $this->assertInstanceOf(ConflictHttpException::class, $e);
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('Kunci idempotensi sudah digunakan dengan parameter refund berbeda.', $e->getMessage());
        }
    }

    public function test_cashier_pos_maps_each_typed_exception_in_id_and_en_even_when_reworded(): void
    {
        $component = new CashierPos;
        $method = new ReflectionMethod(CashierPos::class, 'mapPaymentExceptionToMessage');
        $method->setAccessible(true);

        $cases = [
            [OrderIdempotencyConflictException::class, 'messages.pos.error_conflict'],
            [PaymentIdempotencyConflictException::class, 'messages.pos.error_conflict'],
            [ConflictHttpException::class, 'messages.pos.error_conflict'],
            [ShiftClosedForOrderException::class, 'messages.pos.shift_closed_error'],
            [ShiftClosedForPaymentException::class, 'messages.pos.shift_closed_error'],
            [InsufficientCashTenderedException::class, 'messages.pos.insufficient_cash'],
            [QrisConfirmationExpiredOrUsedException::class, 'messages.pos.qris_expired_or_used'],
            [QrisConfirmationInvalidException::class, 'messages.pos.qris_confirmation_invalid'],
            [OrderAlreadyPaidException::class, 'messages.pos.order_already_paid'],
            [OrderCancelledForPaymentException::class, 'messages.pos.order_cancelled_error'],
        ];

        foreach (['id', 'en'] as $locale) {
            App::setLocale($locale);

            foreach ($cases as [$exceptionClass, $expectedTranslationKey]) {
                $standardException = new $exceptionClass('Original exception text');
                $this->assertSame(
                    __($expectedTranslationKey),
                    $method->invoke($component, $standardException),
                    "Failed mapping {$exceptionClass} in locale {$locale}"
                );

                $rewordedException = new $exceptionClass('COMPLETELY_REWORDED_INTERNAL_DIAGNOSTIC_STRING_XYZ');
                $mapped = $method->invoke($component, $rewordedException);
                $this->assertSame(
                    __($expectedTranslationKey),
                    $mapped,
                    "Failed mapping reworded {$exceptionClass} in locale {$locale}"
                );
                $this->assertStringNotContainsString('COMPLETELY_REWORDED_INTERNAL_DIAGNOSTIC_STRING_XYZ', $mapped);
            }

            $unknown = new RuntimeException('SENSITIVE_DB_SQL_ERROR_TEXT_12345');
            $fallback = $method->invoke($component, $unknown);
            $this->assertSame(__('messages.pos.payment_failed_generic'), $fallback);
            $this->assertStringNotContainsString('SENSITIVE_DB_SQL_ERROR_TEXT_12345', $fallback);
        }
    }

    public function test_order_refund_maps_each_typed_exception_in_id_and_en_even_when_reworded(): void
    {
        $component = new OrderRefund;
        $component->order = $this->createUnpaidOrder();
        $method = new ReflectionMethod(OrderRefund::class, 'mapRefundExceptionToMessage');
        $method->setAccessible(true);

        $cases = [
            [RefundIdempotencyConflictException::class, 'messages.refund.error_conflict'],
            [ConflictHttpException::class, 'messages.refund.error_conflict'],
            [RefundReasonRequiredException::class, 'messages.refund.reason_required'],
            [UnpaidOrderNotRefundableException::class, 'messages.refund.error_unpaid_order'],
            [OrderAlreadyRefundedException::class, 'messages.refund.error_already_refunded'],
            [CashRefundRequiresOpenShiftException::class, 'messages.refund.no_open_shift_cash'],
            [RefundExceedsRemainingBalanceException::class, 'messages.refund.error_over_refund'],
            [RefundTotalExceedsPaidAmountException::class, 'messages.refund.error_over_refund'],
        ];

        foreach (['id', 'en'] as $locale) {
            App::setLocale($locale);

            foreach ($cases as [$exceptionClass, $expectedTranslationKey]) {
                $standardException = new $exceptionClass('Original exception text');
                $this->assertSame(
                    __($expectedTranslationKey),
                    $method->invoke($component, $standardException),
                    "Failed mapping {$exceptionClass} in locale {$locale}"
                );

                $rewordedException = new $exceptionClass('COMPLETELY_REWORDED_INTERNAL_DIAGNOSTIC_STRING_XYZ');
                $mapped = $method->invoke($component, $rewordedException);
                $this->assertSame(
                    __($expectedTranslationKey),
                    $mapped,
                    "Failed mapping reworded {$exceptionClass} in locale {$locale}"
                );
                $this->assertStringNotContainsString('COMPLETELY_REWORDED_INTERNAL_DIAGNOSTIC_STRING_XYZ', $mapped);
            }

            $unknown = new RuntimeException('SENSITIVE_DB_SQL_ERROR_TEXT_67890');
            $fallback = $method->invoke($component, $unknown);
            $this->assertSame(__('messages.refund.error_generic'), $fallback);
            $this->assertStringNotContainsString('SENSITIVE_DB_SQL_ERROR_TEXT_67890', $fallback);
        }
    }

    public function test_lang_id_and_en_messages_have_identical_key_sets(): void
    {
        $idMessages = require base_path('lang/id/messages.php');
        $enMessages = require base_path('lang/en/messages.php');

        $flattenKeys = function (array $array, string $prefix = '') use (&$flattenKeys): array {
            $keys = [];
            foreach ($array as $key => $value) {
                $fullKey = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
                if (is_array($value)) {
                    $keys = array_merge($keys, $flattenKeys($value, $fullKey));
                } else {
                    $keys[] = $fullKey;
                }
            }
            sort($keys);

            return $keys;
        };

        $idKeys = $flattenKeys($idMessages);
        $enKeys = $flattenKeys($enMessages);

        $this->assertSame([], array_values(array_diff($idKeys, $enKeys)), 'Keys in lang/id/messages.php missing from lang/en/messages.php');
        $this->assertSame([], array_values(array_diff($enKeys, $idKeys)), 'Keys in lang/en/messages.php missing from lang/id/messages.php');
    }
}
