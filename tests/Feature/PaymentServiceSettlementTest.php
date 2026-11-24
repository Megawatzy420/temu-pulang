<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ShiftService;
use DomainException;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class PaymentServiceSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $cashier;

    protected Shift $shift;

    protected OrderService $orderService;

    protected PaymentService $paymentService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->owner = User::where('email', 'owner@temupulang.local')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();

        $this->orderService = app(OrderService::class);
        $this->paymentService = app(PaymentService::class);

        $this->shift = app(ShiftService::class)->openShift(
            user: $this->cashier,
            openingCash: 100000,
            openingNote: 'Kasir buka shift'
        );
    }

    protected function createTestOrder(int $quantity = 1, ?Shift $shift = null): Order
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();

        return $this->orderService->createOrder(
            cashier: $this->cashier,
            shift: $shift ?? $this->shift,
            orderPayload: [
                'order_type' => Order::TYPE_DINE_IN,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => $quantity],
                ],
            ]
        );
    }

    public function test_single_cash_payment_settles_successfully(): void
    {
        $order = $this->createTestOrder();
        $total = $order->total_amount;
        $tendered = $total + 10000;
        $change = 10000;

        $transaction = $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order,
            tenders: [
                [
                    'payment_method' => 'cash',
                    'amount' => $total,
                    'tendered_amount' => $tendered,
                    'change_amount' => $change,
                ],
            ]
        );

        $this->assertInstanceOf(PaymentTransaction::class, $transaction);
        $this->assertEquals($total, $transaction->total_amount);
        $this->assertEquals('completed', $transaction->status);
        $this->assertStringStartsWith('PAY-', $transaction->payment_transaction_number);
        $this->assertEquals(1, $transaction->daily_sequence);

        // Child payments
        $this->assertCount(1, $transaction->payments);
        $payment = $transaction->payments->first();
        $this->assertEquals('cash', $payment->payment_method);
        $this->assertEquals($total, $payment->amount);
        $this->assertEquals($tendered, $payment->tendered_amount);
        $this->assertEquals($change, $payment->change_amount);
        $this->assertEquals($order->id, $payment->order_id);

        // Order status
        $freshOrder = $order->fresh();
        $this->assertEquals(Order::PAYMENT_STATUS_PAID, $freshOrder->payment_status);
        $this->assertEquals(Order::STATUS_PAID, $freshOrder->status);
        $this->assertNotNull($freshOrder->paid_at);

        // Status history recorded
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'to_status' => Order::STATUS_PAID,
        ]);

        // Audit log recorded
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'payment.settled',
            'subject_id' => $transaction->id,
        ]);
    }

    public function test_single_non_cash_payment_settles_successfully(): void
    {
        $order = $this->createTestOrder();
        $total = $order->total_amount;

        $transaction = $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order,
            tenders: [
                [
                    'payment_method' => 'debit',
                    'amount' => $total,
                    'tendered_amount' => $total,
                    'change_amount' => 0,
                    'reference_number' => 'BCA-DEBIT-789012',
                ],
            ]
        );

        $this->assertEquals(1, $transaction->payments()->count());
        $payment = $transaction->payments()->first();
        $this->assertEquals('debit', $payment->payment_method);
        $this->assertEquals($total, $payment->amount);
        $this->assertEquals('BCA-DEBIT-789012', $payment->reference_number);
        $this->assertEquals(0, $payment->change_amount);

        $this->assertEquals(Order::PAYMENT_STATUS_PAID, $order->fresh()->payment_status);
    }

    public function test_split_payment_with_cash_and_qris(): void
    {
        $order = $this->createTestOrder(quantity: 2);
        $total = $order->total_amount;
        $this->assertGreaterThan(10000, $total);

        $cashAmount = 10000;
        $qrisAmount = $total - $cashAmount;

        $qris = $this->paymentService->createQrisConfirmation(
            cashier: $this->cashier,
            order: $order,
            expectedAmount: $qrisAmount,
            referenceNumber: 'QRIS-SPLIT-999'
        );

        $transaction = $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order,
            tenders: [
                [
                    'payment_method' => 'cash',
                    'amount' => $cashAmount,
                    'tendered_amount' => $cashAmount,
                    'change_amount' => 0,
                ],
                [
                    'payment_method' => 'qris',
                    'amount' => $qrisAmount,
                    'tendered_amount' => $qrisAmount,
                    'change_amount' => 0,
                    'qris_confirmation_id' => $qris->id,
                ],
            ]
        );

        $this->assertCount(2, $transaction->payments);
        $this->assertEquals($total, $transaction->total_amount);

        // QRIS confirmation is atomically consumed
        $this->assertTrue($qris->fresh()->isConsumed());
        $this->assertNotNull($qris->fresh()->consumed_at);

        // Cash tender verified
        $cashPayment = $transaction->payments->where('payment_method', 'cash')->first();
        $this->assertNotNull($cashPayment);
        $this->assertEquals($cashAmount, $cashPayment->amount);

        // QRIS tender verified
        $qrisPayment = $transaction->payments->where('payment_method', 'qris')->first();
        $this->assertNotNull($qrisPayment);
        $this->assertEquals($qrisAmount, $qrisPayment->amount);
        $this->assertEquals($this->cashier->id, $qrisPayment->qris_confirmed_by_user_id);
        $this->assertEquals('QRIS-SPLIT-999', $qrisPayment->reference_number);
    }

    public function test_settlement_rejects_empty_tenders(): void
    {
        $order = $this->createTestOrder();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tenders');

        $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order,
            tenders: []
        );
    }

    public function test_settlement_rejects_incorrect_tender_total(): void
    {
        $order = $this->createTestOrder();
        $total = $order->total_amount;

        // Less than order total
        try {
            $this->paymentService->settlePayment(
                cashier: $this->cashier,
                shift: $this->shift,
                order: $order,
                tenders: [
                    [
                        'payment_method' => 'cash',
                        'amount' => $total - 1000,
                        'tendered_amount' => $total,
                        'change_amount' => 1000,
                    ],
                ]
            );
            $this->fail('Should have failed due to amount mismatch');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak sesuai dengan total tagihan', $e->getMessage());
        }

        // More than order total
        try {
            $this->paymentService->settlePayment(
                cashier: $this->cashier,
                shift: $this->shift,
                order: $order,
                tenders: [
                    [
                        'payment_method' => 'cash',
                        'amount' => $total + 5000,
                        'tendered_amount' => $total + 5000,
                        'change_amount' => 0,
                    ],
                ]
            );
            $this->fail('Should have failed due to amount mismatch');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak sesuai dengan total tagihan', $e->getMessage());
        }
    }

    public function test_settlement_rejects_invalid_tender_types_and_amounts(): void
    {
        $order = $this->createTestOrder();
        $total = $order->total_amount;

        // Non-integer float
        try {
            $this->paymentService->settlePayment(
                cashier: $this->cashier,
                shift: $this->shift,
                order: $order,
                tenders: [
                    [
                        'payment_method' => 'cash',
                        'amount' => (float) $total,
                        'tendered_amount' => (int) $total,
                    ],
                ]
            );
            $this->fail('Float amount should be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('bilangan bulat Rupiah', $e->getMessage());
        }

        // Negative or zero amount
        try {
            $this->paymentService->settlePayment(
                cashier: $this->cashier,
                shift: $this->shift,
                order: $order,
                tenders: [
                    [
                        'payment_method' => 'cash',
                        'amount' => 0,
                        'tendered_amount' => 0,
                    ],
                ]
            );
            $this->fail('Zero amount should be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('lebih besar dari 0', $e->getMessage());
        }
    }

    public function test_settlement_rejects_insufficient_cash_and_incorrect_change(): void
    {
        $order = $this->createTestOrder();
        $total = $order->total_amount;

        // Cash tendered < amount
        try {
            $this->paymentService->settlePayment(
                cashier: $this->cashier,
                shift: $this->shift,
                order: $order,
                tenders: [
                    [
                        'payment_method' => 'cash',
                        'amount' => $total,
                        'tendered_amount' => $total - 5000,
                    ],
                ]
            );
            $this->fail('Tendered < amount should be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('kurang dari nilai tagihan', $e->getMessage());
        }

        // Change amount mismatch
        try {
            $this->paymentService->settlePayment(
                cashier: $this->cashier,
                shift: $this->shift,
                order: $order,
                tenders: [
                    [
                        'payment_method' => 'cash',
                        'amount' => $total,
                        'tendered_amount' => $total + 20000,
                        'change_amount' => 15000, // Should be 20000
                    ],
                ]
            );
            $this->fail('Incorrect change amount should be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('kembalian tunai tidak sesuai', $e->getMessage());
        }

        // Non-cash with change
        try {
            $this->paymentService->settlePayment(
                cashier: $this->cashier,
                shift: $this->shift,
                order: $order,
                tenders: [
                    [
                        'payment_method' => 'debit',
                        'amount' => $total,
                        'tendered_amount' => $total + 5000,
                        'change_amount' => 5000,
                    ],
                ]
            );
            $this->fail('Non-cash with change should be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('harus tepat sama', $e->getMessage());
        }
    }

    public function test_qris_without_confirmation_is_rejected(): void
    {
        $order = $this->createTestOrder();
        $total = $order->total_amount;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('qris_confirmation_id');

        $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order,
            tenders: [
                [
                    'payment_method' => 'qris',
                    'amount' => $total,
                    'tendered_amount' => $total,
                    'qris_confirmation_id' => null,
                ],
            ]
        );
    }

    public function test_qris_confirmation_belonging_to_another_order_is_rejected(): void
    {
        $order1 = $this->createTestOrder();
        $order2 = $this->createTestOrder();

        $qrisForOrder1 = $this->paymentService->createQrisConfirmation(
            cashier: $this->cashier,
            order: $order1,
            expectedAmount: $order2->total_amount
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bukan milik pesanan ini');

        $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order2,
            tenders: [
                [
                    'payment_method' => 'qris',
                    'amount' => $order2->total_amount,
                    'tendered_amount' => $order2->total_amount,
                    'qris_confirmation_id' => $qrisForOrder1->id,
                ],
            ]
        );
    }

    public function test_qris_amount_mismatch_is_rejected(): void
    {
        $order = $this->createTestOrder();
        $total = $order->total_amount;

        $qris = $this->paymentService->createQrisConfirmation(
            cashier: $this->cashier,
            order: $order,
            expectedAmount: $total - 5000
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tidak sesuai dengan nilai pembayaran');

        $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order,
            tenders: [
                [
                    'payment_method' => 'qris',
                    'amount' => $total,
                    'tendered_amount' => $total,
                    'qris_confirmation_id' => $qris->id,
                ],
            ]
        );
    }

    public function test_reusing_already_consumed_qris_confirmation_is_rejected(): void
    {
        $order1 = $this->createTestOrder();
        $total1 = $order1->total_amount;

        $qris = $this->paymentService->createQrisConfirmation(
            cashier: $this->cashier,
            order: $order1,
            expectedAmount: $total1
        );

        // Settle order1 first
        $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order1,
            tenders: [
                [
                    'payment_method' => 'qris',
                    'amount' => $total1,
                    'tendered_amount' => $total1,
                    'qris_confirmation_id' => $qris->id,
                ],
            ]
        );

        $this->assertTrue($qris->fresh()->isConsumed());

        // Attempt to reuse consumed confirmation
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sudah pernah digunakan');

        // Create fresh order and update qris order_id directly to simulate replay
        $order2 = $this->createTestOrder();
        DB::table('qris_confirmations')->where('id', $qris->id)->update([
            'order_id' => $order2->id,
            'expected_amount' => $order2->total_amount,
        ]);

        $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order2,
            tenders: [
                [
                    'payment_method' => 'qris',
                    'amount' => $order2->total_amount,
                    'tendered_amount' => $order2->total_amount,
                    'qris_confirmation_id' => $qris->id,
                ],
            ]
        );
    }

    public function test_qris_confirmation_ownership_rejects_unauthorized_cashier_and_allows_owner(): void
    {
        $order = $this->createTestOrder();
        $total = $order->total_amount;

        // Cashier A creates QRIS confirmation
        $qris = $this->paymentService->createQrisConfirmation(
            cashier: $this->cashier,
            order: $order,
            expectedAmount: $total
        );

        // Another cashier (Cashier B)
        $cashierB = User::factory()->create();
        $cashierRole = Role::where('slug', 'cashier')->firstOrFail();
        $cashierB->roles()->attach($cashierRole);

        // Open shift for cashier B: close current shift first, open for cashier B
        app(ShiftService::class)->closeShift(
            shift: $this->shift,
            closingUser: $this->cashier,
            actualCash: 100000,
            closingNote: 'Close shift for cashier B test'
        );

        $shiftB = app(ShiftService::class)->openShift(
            user: $cashierB,
            openingCash: 100000,
            openingNote: 'Shift cashier B'
        );

        // Cashier B attempts to process Cashier A confirmation -> Rejected
        try {
            $this->paymentService->settlePayment(
                cashier: $cashierB,
                shift: $shiftB,
                order: $order,
                tenders: [
                    [
                        'payment_method' => 'qris',
                        'amount' => $total,
                        'tendered_amount' => $total,
                        'qris_confirmation_id' => $qris->id,
                    ],
                ]
            );
            $this->fail('Cashier B should not be able to process Cashier A QRIS confirmation');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('hanya dapat diproses oleh kasir yang mengonfirmasinya atau Owner', $e->getMessage());
        }

        // Owner CAN process Cashier A confirmation (Owner authorization exception)
        $trx = $this->paymentService->settlePayment(
            cashier: $this->owner,
            shift: $shiftB,
            order: $order,
            tenders: [
                [
                    'payment_method' => 'qris',
                    'amount' => $total,
                    'tendered_amount' => $total,
                    'qris_confirmation_id' => $qris->id,
                ],
            ]
        );

        $this->assertInstanceOf(PaymentTransaction::class, $trx);
        $this->assertTrue($qris->fresh()->isConsumed());
    }

    public function test_identical_idempotent_retry_returns_same_transaction(): void
    {
        $order = $this->createTestOrder();
        $total = $order->total_amount;
        $idempotencyKey = (string) Str::uuid();

        $tenders = [
            [
                'payment_method' => 'cash',
                'amount' => $total,
                'tendered_amount' => $total + 5000,
                'change_amount' => 5000,
            ],
        ];

        $trx1 = $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order,
            tenders: $tenders,
            idempotencyKey: $idempotencyKey
        );

        // Exact retry with identical idempotencyKey and tenders
        $trx2 = $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order,
            tenders: $tenders,
            idempotencyKey: $idempotencyKey
        );

        $this->assertEquals($trx1->id, $trx2->id);
        $this->assertEquals($trx1->payment_transaction_number, $trx2->payment_transaction_number);
        $this->assertEquals(1, PaymentTransaction::count());
        $this->assertEquals(1, Payment::count());
    }

    public function test_same_idempotency_key_with_different_fingerprint_throws_conflict_409(): void
    {
        $order = $this->createTestOrder();
        $total = $order->total_amount;
        $idempotencyKey = (string) Str::uuid();

        // First request with cash
        $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order,
            tenders: [
                [
                    'payment_method' => 'cash',
                    'amount' => $total,
                    'tendered_amount' => $total,
                    'change_amount' => 0,
                ],
            ],
            idempotencyKey: $idempotencyKey
        );

        // Second request reusing the same idempotency key but with debit method
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Kunci idempotensi sudah digunakan');

        $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order,
            tenders: [
                [
                    'payment_method' => 'debit',
                    'amount' => $total,
                    'tendered_amount' => $total,
                    'change_amount' => 0,
                ],
            ],
            idempotencyKey: $idempotencyKey
        );
    }

    public function test_same_idempotency_key_with_different_shift_throws_conflict_409(): void
    {
        $order = $this->createTestOrder();
        $total = $order->total_amount;
        $idempotencyKey = (string) Str::uuid();

        $tenders = [
            [
                'payment_method' => 'cash',
                'amount' => $total,
                'tendered_amount' => $total,
                'change_amount' => 0,
            ],
        ];

        // First request on Shift 1
        $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order,
            tenders: $tenders,
            idempotencyKey: $idempotencyKey
        );

        // Close shift 1 and open shift 2
        app(ShiftService::class)->closeShift(
            shift: $this->shift,
            closingUser: $this->cashier,
            actualCash: 100000 + $total,
            closingNote: 'Closing shift 1'
        );

        $shift2 = app(ShiftService::class)->openShift(
            user: $this->cashier,
            openingCash: 100000,
            openingNote: 'Opening shift 2'
        );

        // Attempting to reuse the same idempotency key on shift 2 must trigger 409 Conflict due to fingerprint mismatch
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Kunci idempotensi sudah digunakan');

        $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $shift2,
            order: $order,
            tenders: $tenders,
            idempotencyKey: $idempotencyKey
        );
    }

    public function test_same_idempotency_key_with_different_cashier_throws_conflict_409(): void
    {
        $order = $this->createTestOrder();
        $total = $order->total_amount;
        $idempotencyKey = (string) Str::uuid();

        $tenders = [
            [
                'payment_method' => 'cash',
                'amount' => $total,
                'tendered_amount' => $total,
                'change_amount' => 0,
            ],
        ];

        // First request with Cashier
        $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order,
            tenders: $tenders,
            idempotencyKey: $idempotencyKey
        );

        // Second request reusing the same idempotency key but with Owner (different cashier_user_id)
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Kunci idempotensi sudah digunakan');

        $this->paymentService->settlePayment(
            cashier: $this->owner,
            shift: $this->shift,
            order: $order,
            tenders: $tenders,
            idempotencyKey: $idempotencyKey
        );
    }

    public function test_duplicate_settlement_for_already_paid_order_is_rejected(): void
    {
        $order = $this->createTestOrder();
        $total = $order->total_amount;

        // First payment succeeds
        $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order,
            tenders: [
                [
                    'payment_method' => 'cash',
                    'amount' => $total,
                    'tendered_amount' => $total,
                    'change_amount' => 0,
                ],
            ]
        );

        $this->assertEquals(Order::PAYMENT_STATUS_PAID, $order->fresh()->payment_status);

        // Attempting to settle again with a fresh idempotency key must be rejected
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Pesanan ini sudah dibayar');

        $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order->fresh(),
            tenders: [
                [
                    'payment_method' => 'cash',
                    'amount' => $total,
                    'tendered_amount' => $total,
                    'change_amount' => 0,
                ],
            ]
        );
    }

    public function test_inactive_or_unauthorized_shift_is_rejected(): void
    {
        $order = $this->createTestOrder();
        $total = $order->total_amount;

        // 1. Closed shift rejected: close the active shift first
        app(ShiftService::class)->closeShift(
            shift: $this->shift,
            closingUser: $this->cashier,
            actualCash: 100000,
            closingNote: 'Shift ditutup untuk pengujian'
        );

        try {
            $this->paymentService->settlePayment(
                cashier: $this->cashier,
                shift: $this->shift->fresh(),
                order: $order,
                tenders: [
                    [
                        'payment_method' => 'cash',
                        'amount' => $total,
                        'tendered_amount' => $total,
                        'change_amount' => 0,
                    ],
                ]
            );
            $this->fail('Closed shift should be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Shift kasir sudah ditutup', $e->getMessage());
        }

        // Re-open a fresh shift for remaining sub-tests
        $reopenedShift = app(ShiftService::class)->openShift(
            user: $this->cashier,
            openingCash: 100000,
            openingNote: 'Re-opened cashier shift'
        );

        // 2. Another cashier attempting to settle on different cashier shift
        $otherCashier = User::factory()->create();
        $cashierRole = Role::where('slug', 'cashier')->firstOrFail();
        $otherCashier->roles()->attach($cashierRole);

        try {
            $this->paymentService->settlePayment(
                cashier: $otherCashier,
                shift: $reopenedShift, // shift belongs to $this->cashier
                order: $order,
                tenders: [
                    [
                        'payment_method' => 'cash',
                        'amount' => $total,
                        'tendered_amount' => $total,
                        'change_amount' => 0,
                    ],
                ]
            );
            $this->fail('Unauthorized cashier should be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak memiliki akses ke shift ini', $e->getMessage());
        }

        // 3. Owner CAN settle on a cashier open shift (Master bypass)
        $orderForOwner = $this->createTestOrder(shift: $reopenedShift);
        $trx = $this->paymentService->settlePayment(
            cashier: $this->owner,
            shift: $reopenedShift,
            order: $orderForOwner,
            tenders: [
                [
                    'payment_method' => 'cash',
                    'amount' => $orderForOwner->total_amount,
                    'tendered_amount' => $orderForOwner->total_amount,
                    'change_amount' => 0,
                ],
            ]
        );
        $this->assertInstanceOf(PaymentTransaction::class, $trx);
    }

    public function test_rollback_behavior_after_settlement_failure(): void
    {
        $order = $this->createTestOrder();
        $total = $order->total_amount;

        $qris = $this->paymentService->createQrisConfirmation(
            cashier: $this->cashier,
            order: $order,
            expectedAmount: $total
        );

        $this->assertTrue($qris->isPending());

        // Simulate a failure during settlement by throwing inside an Eloquent event or mock
        Payment::saving(function () {
            throw new Exception('Simulasi kegagalan sistem penyimpanan tender.');
        });

        try {
            $this->paymentService->settlePayment(
                cashier: $this->cashier,
                shift: $this->shift,
                order: $order,
                tenders: [
                    [
                        'payment_method' => 'qris',
                        'amount' => $total,
                        'tendered_amount' => $total,
                        'qris_confirmation_id' => $qris->id,
                    ],
                ]
            );
            $this->fail('Settlement should have failed');
        } catch (Exception $e) {
            $this->assertEquals('Simulasi kegagalan sistem penyimpanan tender.', $e->getMessage());
        } finally {
            // Remove event listener
            Payment::flushEventListeners();
        }

        // Verify full rollback
        $this->assertEquals(0, PaymentTransaction::count());
        $this->assertEquals(0, Payment::count());

        // QRIS confirmation must roll back to pending
        $this->assertTrue($qris->fresh()->isPending());
        $this->assertNull($qris->fresh()->consumed_at);

        // Order status must remain unpaid
        $freshOrder = $order->fresh();
        $this->assertEquals(Order::PAYMENT_STATUS_UNPAID, $freshOrder->payment_status);
        $this->assertEquals(Order::STATUS_AWAITING_PAYMENT, $freshOrder->status);
        $this->assertNull($freshOrder->paid_at);
    }

    /**
     * Verifies sequential counter increments and format under single-threaded execution.
     * Note: Concurrency safety in production is guarded by ShopSetting row locking (lockForUpdate)
     * and database UNIQUE constraints (uniq_pay_trx_biz_date_seq, uniq_pay_trx_biz_date_num),
     * which are tested via database constraint tests; true parallel multi-process concurrency
     * requires external multi-threaded load testing.
     */
    public function test_payment_transaction_numbering_under_sequential_settlements(): void
    {
        $order1 = $this->createTestOrder();
        $order2 = $this->createTestOrder();
        $order3 = $this->createTestOrder();

        $trx1 = $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order1,
            tenders: [
                ['payment_method' => 'cash', 'amount' => $order1->total_amount, 'tendered_amount' => $order1->total_amount],
            ]
        );

        $trx2 = $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order2,
            tenders: [
                ['payment_method' => 'cash', 'amount' => $order2->total_amount, 'tendered_amount' => $order2->total_amount],
            ]
        );

        $trx3 = $this->paymentService->settlePayment(
            cashier: $this->cashier,
            shift: $this->shift,
            order: $order3,
            tenders: [
                ['payment_method' => 'cash', 'amount' => $order3->total_amount, 'tendered_amount' => $order3->total_amount],
            ]
        );

        $this->assertEquals(1, $trx1->daily_sequence);
        $this->assertEquals(2, $trx2->daily_sequence);
        $this->assertEquals(3, $trx3->daily_sequence);

        $businessDate = $this->orderService->calculateBusinessDate();
        $datePart = str_replace('-', '', $businessDate);
        $this->assertEquals("PAY-{$datePart}-0001", $trx1->payment_transaction_number);
        $this->assertEquals("PAY-{$datePart}-0002", $trx2->payment_transaction_number);
        $this->assertEquals("PAY-{$datePart}-0003", $trx3->payment_transaction_number);
    }
}
