<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\QrisConfirmation;
use App\Models\RefundTransaction;
use App\Models\Shift;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ShiftService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase5DatabaseFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $cashier;

    protected Shift $shift;

    protected Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->owner = User::where('email', 'owner@temupulang.local')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();

        $this->shift = app(ShiftService::class)->openShift(
            user: $this->cashier,
            openingCash: 100000,
            openingNote: 'Kas modal test Phase 5'
        );

        $product = Product::where('is_sold_out', false)->firstOrFail();
        $this->order = app(OrderService::class)->createOrder(
            cashier: $this->cashier,
            shift: $this->shift,
            orderPayload: [
                'order_type' => Order::TYPE_DINE_IN,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ]
        );
    }

    public function test_all_phase5_tables_exist_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('qris_confirmations'));
        $this->assertTrue(Schema::hasTable('payment_transactions'));
        $this->assertTrue(Schema::hasTable('payments'));
        $this->assertTrue(Schema::hasTable('refund_transactions'));
        $this->assertTrue(Schema::hasTable('payment_refunds'));
        $this->assertTrue(Schema::hasTable('print_jobs'));

        $this->assertTrue(Schema::hasColumn('orders', 'payment_status'));
        $this->assertEquals(Order::PAYMENT_STATUS_UNPAID, $this->order->fresh()->payment_status);
    }

    public function test_payment_transaction_enforces_unique_order_id(): void
    {
        $pt1 = PaymentTransaction::create([
            'ulid' => (string) Str::ulid(),
            'payment_transaction_number' => 'PAY-20261114-0001',
            'daily_sequence' => 1,
            'business_date' => now()->toDateString(),
            'order_id' => $this->order->id,
            'shift_id' => $this->shift->id,
            'user_id' => $this->cashier->id,
            'total_amount' => $this->order->total_amount,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'fingerprint-1'),
            'status' => 'completed',
        ]);

        $this->assertNotNull($pt1->id);

        $this->expectException(QueryException::class);

        PaymentTransaction::create([
            'ulid' => (string) Str::ulid(),
            'payment_transaction_number' => 'PAY-20261114-0002',
            'daily_sequence' => 2,
            'business_date' => now()->toDateString(),
            'order_id' => $this->order->id, // Duplicate order_id violates UNIQUE
            'shift_id' => $this->shift->id,
            'user_id' => $this->cashier->id,
            'total_amount' => $this->order->total_amount,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'fingerprint-2'),
            'status' => 'completed',
        ]);
    }

    public function test_payment_transaction_and_payment_immutability(): void
    {
        $pt = PaymentTransaction::create([
            'ulid' => (string) Str::ulid(),
            'payment_transaction_number' => 'PAY-20261114-0003',
            'daily_sequence' => 1,
            'business_date' => now()->toDateString(),
            'order_id' => $this->order->id,
            'shift_id' => $this->shift->id,
            'user_id' => $this->cashier->id,
            'total_amount' => $this->order->total_amount,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'fingerprint-3'),
            'status' => 'completed',
        ]);

        $payment = new Payment([
            'payment_transaction_id' => $pt->id,
            'shift_id' => $this->shift->id,
            'payment_method' => 'cash',
            'amount' => $this->order->total_amount,
            'tendered_amount' => $this->order->total_amount + 10000,
            'change_amount' => 10000,
        ]);
        $payment->order_id = $this->order->id;
        $payment->save();

        // 1. Updating PaymentTransaction must throw
        try {
            $pt->update(['total_amount' => 999999]);
            $this->fail('PaymentTransaction update should have thrown DomainException');
        } catch (DomainException $e) {
            $this->assertStringContainsString('permanen dan tidak dapat diubah', $e->getMessage());
        }

        // 2. Deleting PaymentTransaction must throw
        try {
            $pt->delete();
            $this->fail('PaymentTransaction delete should have thrown DomainException');
        } catch (DomainException $e) {
            $this->assertStringContainsString('permanen dan tidak dapat dihapus', $e->getMessage());
        }

        // 3. Updating Payment must throw
        try {
            $payment->update(['amount' => 50000]);
            $this->fail('Payment update should have thrown DomainException');
        } catch (DomainException $e) {
            $this->assertStringContainsString('permanen dan tidak dapat diubah', $e->getMessage());
        }

        // 4. Deleting Payment must throw
        try {
            $payment->delete();
            $this->fail('Payment delete should have thrown DomainException');
        } catch (DomainException $e) {
            $this->assertStringContainsString('permanen dan tidak dapat dihapus', $e->getMessage());
        }
    }

    public function test_refund_transaction_and_payment_refund_immutability(): void
    {
        $pt = PaymentTransaction::create([
            'ulid' => (string) Str::ulid(),
            'payment_transaction_number' => 'PAY-20261114-0004',
            'daily_sequence' => 1,
            'business_date' => now()->toDateString(),
            'order_id' => $this->order->id,
            'shift_id' => $this->shift->id,
            'user_id' => $this->cashier->id,
            'total_amount' => $this->order->total_amount,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'fingerprint-4'),
            'status' => 'completed',
        ]);

        $payment = new Payment([
            'payment_transaction_id' => $pt->id,
            'shift_id' => $this->shift->id,
            'payment_method' => 'cash',
            'amount' => $this->order->total_amount,
            'tendered_amount' => $this->order->total_amount,
            'change_amount' => 0,
        ]);
        $payment->order_id = $this->order->id;
        $payment->save();

        $rt = RefundTransaction::create([
            'ulid' => (string) Str::ulid(),
            'refund_transaction_number' => 'RFD-20261114-0001',
            'daily_sequence' => 1,
            'business_date' => now()->toDateString(),
            'order_id' => $this->order->id,
            'authorized_by_user_id' => $this->owner->id,
            'total_refunded_amount' => 5000,
            'reason' => 'Sebagian dibatalkan',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'rfd-fingerprint-1'),
        ]);

        $pr = PaymentRefund::create([
            'refund_transaction_id' => $rt->id,
            'payment_id' => $payment->id,
            'order_id' => $this->order->id,
            'shift_id' => $this->shift->id,
            'original_payment_method' => 'cash',
            'refund_method' => 'cash',
            'amount' => 5000,
        ]);

        // 1. Updating RefundTransaction must throw
        try {
            $rt->update(['reason' => 'Changed reason']);
            $this->fail('RefundTransaction update should have thrown DomainException');
        } catch (DomainException $e) {
            $this->assertStringContainsString('permanen dan tidak dapat diubah', $e->getMessage());
        }

        // 2. Deleting RefundTransaction must throw
        try {
            $rt->delete();
            $this->fail('RefundTransaction delete should have thrown DomainException');
        } catch (DomainException $e) {
            $this->assertStringContainsString('permanen dan tidak dapat dihapus', $e->getMessage());
        }

        // 3. Updating PaymentRefund must throw
        try {
            $pr->update(['amount' => 10000]);
            $this->fail('PaymentRefund update should have thrown DomainException');
        } catch (DomainException $e) {
            $this->assertStringContainsString('permanen dan tidak dapat diubah', $e->getMessage());
        }

        // 4. Deleting PaymentRefund must throw
        try {
            $pr->delete();
            $this->fail('PaymentRefund delete should have thrown DomainException');
        } catch (DomainException $e) {
            $this->assertStringContainsString('permanen dan tidak dapat dihapus', $e->getMessage());
        }
    }

    public function test_qris_confirmation_lifecycle(): void
    {
        $qris = QrisConfirmation::create([
            'ulid' => (string) Str::ulid(),
            'order_id' => $this->order->id,
            'user_id' => $this->cashier->id,
            'expected_amount' => $this->order->total_amount,
            'reference_number' => 'BCA-QRIS-998877',
            'confirmed_at' => now(),
            'status' => 'pending',
        ]);

        $this->assertTrue($qris->isPending());
        $this->assertFalse($qris->isConsumed());

        $qris->update([
            'status' => 'consumed',
            'consumed_at' => now(),
        ]);

        $this->assertTrue($qris->fresh()->isConsumed());
    }

    public function test_print_job_claim_token_and_reprint_lineage(): void
    {
        $pj1 = PrintJob::create([
            'ulid' => (string) Str::ulid(),
            'order_id' => $this->order->id,
            'job_type' => 'customer_receipt',
            'printer_destination' => 'POS80',
            'paper_width' => 80,
            'is_reprint' => false,
            'status' => 'pending',
            'attempts' => 0,
            'max_attempts' => 3,
        ]);

        $claimToken = (string) Str::uuid();
        $pj1->update([
            'status' => 'processing',
            'locked_at' => now(),
            'claim_token' => $claimToken,
            'attempts' => 1,
        ]);

        $this->assertEquals($claimToken, $pj1->fresh()->claim_token);
        $this->assertEquals(1, $pj1->fresh()->attempts);

        // Child reprint references original
        $pjReprint = PrintJob::create([
            'ulid' => (string) Str::ulid(),
            'order_id' => $this->order->id,
            'original_print_job_id' => $pj1->id,
            'job_type' => 'customer_receipt',
            'printer_destination' => 'POS80',
            'paper_width' => 80,
            'is_reprint' => true,
            'status' => 'pending',
            'attempts' => 0,
            'max_attempts' => 3,
        ]);

        $this->assertTrue($pjReprint->is_reprint);
        $this->assertEquals($pj1->id, $pjReprint->original_print_job_id);
        $this->assertEquals($pj1->id, $pjReprint->originalPrintJob->id);
    }

    public function test_payment_transaction_rejects_duplicate_idempotency_key(): void
    {
        $idempotencyKey = (string) Str::uuid();

        PaymentTransaction::create([
            'ulid' => (string) Str::ulid(),
            'payment_transaction_number' => 'PAY-20261114-0010',
            'daily_sequence' => 10,
            'business_date' => now()->toDateString(),
            'order_id' => $this->order->id,
            'shift_id' => $this->shift->id,
            'user_id' => $this->cashier->id,
            'total_amount' => $this->order->total_amount,
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => hash('sha256', 'fingerprint-10'),
            'status' => 'completed',
        ]);

        $product = Product::where('is_sold_out', false)->firstOrFail();
        $order2 = app(OrderService::class)->createOrder(
            cashier: $this->cashier,
            shift: $this->shift,
            orderPayload: [
                'order_type' => Order::TYPE_DINE_IN,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ]
        );

        $this->expectException(QueryException::class);

        // Attempt duplicate idempotency key on order2
        PaymentTransaction::create([
            'ulid' => (string) Str::ulid(),
            'payment_transaction_number' => 'PAY-20261114-0011',
            'daily_sequence' => 11,
            'business_date' => now()->toDateString(),
            'order_id' => $order2->id,
            'shift_id' => $this->shift->id,
            'user_id' => $this->cashier->id,
            'total_amount' => $order2->total_amount,
            'idempotency_key' => $idempotencyKey, // Duplicate violates UNIQUE
            'request_fingerprint' => hash('sha256', 'fingerprint-11'),
            'status' => 'completed',
        ]);
    }

    public function test_refund_transaction_rejects_duplicate_idempotency_key(): void
    {
        $idempotencyKey = (string) Str::uuid();

        RefundTransaction::create([
            'ulid' => (string) Str::ulid(),
            'refund_transaction_number' => 'RFD-20261114-0010',
            'daily_sequence' => 10,
            'business_date' => now()->toDateString(),
            'order_id' => $this->order->id,
            'authorized_by_user_id' => $this->owner->id,
            'total_refunded_amount' => 5000,
            'reason' => 'Refund awal',
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => hash('sha256', 'rfd-fp-10'),
        ]);

        $this->expectException(QueryException::class);

        RefundTransaction::create([
            'ulid' => (string) Str::ulid(),
            'refund_transaction_number' => 'RFD-20261114-0011',
            'daily_sequence' => 11,
            'business_date' => now()->toDateString(),
            'order_id' => $this->order->id,
            'authorized_by_user_id' => $this->owner->id,
            'total_refunded_amount' => 5000,
            'reason' => 'Refund kedua dengan key duplikat',
            'idempotency_key' => $idempotencyKey, // Duplicate violates UNIQUE
            'request_fingerprint' => hash('sha256', 'rfd-fp-11'),
        ]);
    }

    public function test_payment_transaction_rejects_duplicate_business_date_sequence(): void
    {
        PaymentTransaction::create([
            'ulid' => (string) Str::ulid(),
            'payment_transaction_number' => 'PAY-20261114-0020',
            'daily_sequence' => 99,
            'business_date' => '2026-11-14',
            'order_id' => $this->order->id,
            'shift_id' => $this->shift->id,
            'user_id' => $this->cashier->id,
            'total_amount' => $this->order->total_amount,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'fingerprint-20'),
            'status' => 'completed',
        ]);

        $product = Product::where('is_sold_out', false)->firstOrFail();
        $order2 = app(OrderService::class)->createOrder(
            cashier: $this->cashier,
            shift: $this->shift,
            orderPayload: [
                'order_type' => Order::TYPE_DINE_IN,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ]
        );

        $this->expectException(QueryException::class);

        // Same business_date and same daily_sequence (99)
        PaymentTransaction::create([
            'ulid' => (string) Str::ulid(),
            'payment_transaction_number' => 'PAY-20261114-0021',
            'daily_sequence' => 99, // Duplicate for 2026-11-14 violates uniq_pay_trx_biz_date_seq
            'business_date' => '2026-11-14',
            'order_id' => $order2->id,
            'shift_id' => $this->shift->id,
            'user_id' => $this->cashier->id,
            'total_amount' => $order2->total_amount,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'fingerprint-21'),
            'status' => 'completed',
        ]);
    }

    public function test_refund_transaction_rejects_duplicate_business_date_sequence(): void
    {
        RefundTransaction::create([
            'ulid' => (string) Str::ulid(),
            'refund_transaction_number' => 'RFD-20261114-0020',
            'daily_sequence' => 88,
            'business_date' => '2026-11-14',
            'order_id' => $this->order->id,
            'authorized_by_user_id' => $this->owner->id,
            'total_refunded_amount' => 5000,
            'reason' => 'Refund seq test 1',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'rfd-fp-20'),
        ]);

        $this->expectException(QueryException::class);

        // Same business_date and same daily_sequence (88)
        RefundTransaction::create([
            'ulid' => (string) Str::ulid(),
            'refund_transaction_number' => 'RFD-20261114-0021',
            'daily_sequence' => 88, // Duplicate for 2026-11-14 violates uniq_ref_trx_biz_date_seq
            'business_date' => '2026-11-14',
            'order_id' => $this->order->id,
            'authorized_by_user_id' => $this->owner->id,
            'total_refunded_amount' => 5000,
            'reason' => 'Refund seq test 2',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'rfd-fp-21'),
        ]);
    }

    public function test_order_payment_status_is_guarded_from_mass_assignment_and_updatable_explicitly(): void
    {
        $this->assertEquals(Order::PAYMENT_STATUS_UNPAID, $this->order->payment_status);

        // Mass assignment should NOT change payment_status
        $this->order->update(['payment_status' => Order::PAYMENT_STATUS_PAID]);
        $this->assertEquals(Order::PAYMENT_STATUS_UNPAID, $this->order->fresh()->payment_status);

        // Explicit attribute assignment followed by save() succeeds and triggers Eloquent events
        $this->order->payment_status = Order::PAYMENT_STATUS_PAID;
        $this->order->save();
        $this->assertEquals(Order::PAYMENT_STATUS_PAID, $this->order->fresh()->payment_status);
    }

    public function test_payment_order_id_is_guarded_from_mass_assignment_and_assignable_explicitly(): void
    {
        $pt = PaymentTransaction::create([
            'ulid' => (string) Str::ulid(),
            'payment_transaction_number' => 'PAY-20261114-0030',
            'daily_sequence' => 30,
            'business_date' => now()->toDateString(),
            'order_id' => $this->order->id,
            'shift_id' => $this->shift->id,
            'user_id' => $this->cashier->id,
            'total_amount' => $this->order->total_amount,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'fingerprint-30'),
            'status' => 'completed',
        ]);

        // Mass assignment attempt with order_id should NOT populate order_id
        $payment = new Payment([
            'payment_transaction_id' => $pt->id,
            'order_id' => $this->order->id, // mass assignment ignored
            'shift_id' => $this->shift->id,
            'payment_method' => 'cash',
            'amount' => $this->order->total_amount,
            'tendered_amount' => $this->order->total_amount,
            'change_amount' => 0,
        ]);
        $this->assertNull($payment->order_id);

        // Explicit assignment satisfies non-nullable FK and persists cleanly
        $payment->order_id = $this->order->id;
        $payment->save();

        $this->assertNotNull($payment->id);
        $this->assertEquals($this->order->id, $payment->fresh()->order_id);
    }
}
