<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ShiftService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Phase 4 Hardening Tests
 *
 * Covers:
 *  - DB rejection of duplicate daily call numbers (UNIQUE constraint)
 *  - Idempotency: same key → same order returned, different key → new order
 *  - Shift closure TOCTOU: shift closed inside the transaction still rejects the order
 *  - Paid order cancellation rejection via advanceStatus() and cancelOrder()
 */
class OrderHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $cashier;

    protected Shift $shift;

    protected OrderService $orderService;

    protected ShiftService $shiftService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->owner = User::where('email', 'owner@temupulang.local')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();
        $this->orderService = app(OrderService::class);
        $this->shiftService = app(ShiftService::class);

        $this->shift = $this->shiftService->openShift(
            user: $this->cashier,
            openingCash: 200000,
            openingNote: 'Kas modal hardening test',
        );
    }

    // -------------------------------------------------------------------------
    // Task 1 — DB UNIQUE constraint rejects duplicate daily call numbers
    // -------------------------------------------------------------------------

    /**
     * @test
     * The DB-level UNIQUE constraint on (business_date, daily_sequence) must
     * reject a raw INSERT that bypasses application logic.
     */
    public function test_database_rejects_duplicate_daily_sequence(): void
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();

        // Create a legitimate first order
        $order = $this->orderService->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $this->assertEquals(1, $order->daily_sequence);

        // Attempt a raw INSERT with the same business_date + daily_sequence (bypassing service)
        $this->expectException(QueryException::class);

        DB::table('orders')->insert([
            'ulid' => Str::ulid(),
            'order_number' => 'ORD-DUP-999',
            'daily_order_number' => $order->daily_order_number,  // same call number
            'daily_sequence' => $order->daily_sequence,       // same sequence (UNIQUE violation)
            'business_date' => $order->business_date,
            'shift_id' => $this->shift->id,
            'user_id' => $this->cashier->id,
            'order_type' => 'dine_in',
            'status' => 'awaiting_payment',
            'subtotal' => 0,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'service_charge_rate' => 0,
            'service_charge_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 0,
            'idempotency_key' => Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @test
     * The DB-level UNIQUE constraint on (business_date, daily_order_number) must
     * reject a raw INSERT with a duplicate TP-NNN call number on the same business day.
     */
    public function test_database_rejects_duplicate_daily_order_number(): void
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();

        $order = $this->orderService->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $this->expectException(QueryException::class);

        DB::table('orders')->insert([
            'ulid' => Str::ulid(),
            'order_number' => 'ORD-DUP-888',
            'daily_order_number' => $order->daily_order_number,  // duplicate call number
            'daily_sequence' => 9999,                         // unique seq — only the call number conflicts
            'business_date' => $order->business_date,
            'shift_id' => $this->shift->id,
            'user_id' => $this->cashier->id,
            'order_type' => 'dine_in',
            'status' => 'awaiting_payment',
            'subtotal' => 0,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'service_charge_rate' => 0,
            'service_charge_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 0,
            'idempotency_key' => Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Task 2 — Idempotency key lifecycle
    // -------------------------------------------------------------------------

    /**
     * @test
     * Submitting createOrder twice with the SAME idempotency key must return
     * the identical order and must not create a second DB record.
     */
    public function test_same_idempotency_key_returns_existing_order_on_retry(): void
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();
        $stableKey = 'stable-key-'.now()->timestamp;

        $payload = [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => $stableKey,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];

        $order1 = $this->orderService->createOrder($this->cashier, $this->shift, $payload);
        $order2 = $this->orderService->createOrder($this->cashier, $this->shift, $payload);  // retry

        // Must be the exact same order record
        $this->assertEquals($order1->id, $order2->id);
        $this->assertEquals($order1->order_number, $order2->order_number);

        // Only one row must exist in the DB for this key
        $this->assertEquals(1, Order::where('idempotency_key', $stableKey)->count());

        // And exactly one status history entry (not two)
        $this->assertCount(1, $order2->statusHistories);
    }

    /**
     * @test
     * A genuinely new order (new idempotency key) must create a new DB record
     * with an incremented daily sequence.
     */
    public function test_new_idempotency_key_creates_new_order(): void
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();

        $order1 = $this->orderService->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => 'key-order-alpha',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $order2 = $this->orderService->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_TAKEAWAY,
            'idempotency_key' => 'key-order-beta',   // different key → new order
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);

        $this->assertNotEquals($order1->id, $order2->id);
        $this->assertEquals(1, $order1->daily_sequence);
        $this->assertEquals(2, $order2->daily_sequence);
        $this->assertEquals('TP-001', $order1->call_number);
        $this->assertEquals('TP-002', $order2->call_number);
        $this->assertEquals(2, Order::count());
    }

    // -------------------------------------------------------------------------
    // Task 3 — Shift closure TOCTOU (concurrent shift close during order)
    // -------------------------------------------------------------------------

    /**
     * @test
     * If a shift is closed AFTER the pre-flight check but BEFORE the transaction
     * commits, the in-transaction lockForUpdate re-check must reject the order.
     *
     * We simulate this by directly closing the shift row between service call stages.
     * Since SQLite (test DB) does not support true concurrent sessions, we test
     * the guard by verifying the in-transaction check on a manually closed shift.
     */
    public function test_shift_closed_between_preflight_and_transaction_rejects_order(): void
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();

        // Directly mark the shift as closed in the DB (simulating concurrent close)
        // bypassing the booted() guard by using DB::table
        DB::table('shifts')->where('id', $this->shift->id)->update([
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        // Even though our in-memory $this->shift still shows 'open', the service
        // must re-query and lock the DB row inside the transaction and find it closed.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Shift kasir sudah ditutup/');

        $this->orderService->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
    }

    // -------------------------------------------------------------------------
    // Task 4 — Paid order cancellation rejection
    // -------------------------------------------------------------------------

    /**
     * @test
     * advanceStatus() must NOT permit paid → cancelled transition.
     */
    public function test_advance_status_cannot_cancel_paid_order(): void
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();

        $order = $this->orderService->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        // Advance to paid
        $order = $this->orderService->advanceStatus($order, Order::STATUS_PAID, $this->cashier);
        $this->assertEquals(Order::STATUS_PAID, $order->status);

        // Attempting paid → cancelled via advanceStatus must throw
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Transisi status tidak diizinkan/');

        $this->orderService->advanceStatus($order, Order::STATUS_CANCELLED, $this->owner);
    }

    /**
     * @test
     * advanceStatus() must NOT permit queued → cancelled transition.
     */
    public function test_advance_status_cannot_cancel_queued_order(): void
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();

        $order = $this->orderService->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $order = $this->orderService->advanceStatus($order, Order::STATUS_PAID, $this->cashier);
        $order = $this->orderService->advanceStatus($order, Order::STATUS_QUEUED, $this->cashier);
        $this->assertEquals(Order::STATUS_QUEUED, $order->status);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Transisi status tidak diizinkan/');

        $this->orderService->advanceStatus($order, Order::STATUS_CANCELLED, $this->owner);
    }

    /**
     * @test
     * cancelOrder() must NOT permit cancellation of a paid order,
     * even when called by the Owner.
     */
    public function test_cancel_order_rejects_paid_order_even_for_owner(): void
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();

        $order = $this->orderService->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $order = $this->orderService->advanceStatus($order, Order::STATUS_PAID, $this->cashier);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/sudah dibayar tidak dapat dibatalkan/');

        $this->orderService->cancelOrder($order, $this->owner, 'Alasan pembatalan');
    }

    /**
     * @test
     * cancelOrder() must NOT permit cancellation of a queued order.
     */
    public function test_cancel_order_rejects_queued_order(): void
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();

        $order = $this->orderService->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $order = $this->orderService->advanceStatus($order, Order::STATUS_PAID, $this->cashier);
        $order = $this->orderService->advanceStatus($order, Order::STATUS_QUEUED, $this->cashier);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/sudah dibayar tidak dapat dibatalkan/');

        $this->orderService->cancelOrder($order, $this->owner, 'Alasan pembatalan');
    }

    /**
     * @test
     * cancelOrder() must NOT permit cancellation of a preparing order.
     */
    public function test_cancel_order_rejects_preparing_order(): void
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();

        $order = $this->orderService->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $order = $this->orderService->advanceStatus($order, Order::STATUS_PAID, $this->cashier);
        $order = $this->orderService->advanceStatus($order, Order::STATUS_PREPARING, $this->cashier);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/sudah dibayar tidak dapat dibatalkan/');

        $this->orderService->cancelOrder($order, $this->owner, 'Alasan pembatalan');
    }

    /**
     * @test
     * Owner CAN cancel an awaiting_payment order — this must still work.
     */
    public function test_owner_can_cancel_awaiting_payment_order(): void
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();

        $order = $this->orderService->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $this->assertEquals(Order::STATUS_AWAITING_PAYMENT, $order->status);

        $cancelled = $this->orderService->cancelOrder($order, $this->owner, 'Pelanggan berubah pikiran');

        $this->assertEquals(Order::STATUS_CANCELLED, $cancelled->status);
        $this->assertEquals('Pelanggan berubah pikiran', $cancelled->cancellation_reason);
        $this->assertEquals($this->owner->id, $cancelled->cancelled_by_user_id);
        $this->assertNotNull($cancelled->cancelled_at);
    }
}
