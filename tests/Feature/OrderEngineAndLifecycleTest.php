<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\ShopSetting;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ShiftService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class OrderEngineAndLifecycleTest extends TestCase
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

        // Open an active cashier shift via ShiftService
        $this->shift = $this->shiftService->openShift(
            user: $this->cashier,
            openingCash: 200000,
            openingNote: 'Kas modal test'
        );
    }

    public function test_can_create_order_with_line_items_and_frozen_snapshots(): void
    {
        $product = Product::with(['variants', 'modifierGroups.modifiers'])->where('sku', 'PRD-LT-001')->firstOrFail();
        $variant = $product->variants->first();
        $modifier = $product->modifierGroups->first()?->modifiers->first();

        $payload = [
            'order_type' => Order::TYPE_DINE_IN,
            'customer_name' => 'Dimas',
            'notes' => 'Tolong sedotan kertas',
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant?->id,
                    'quantity' => 2,
                    'notes' => 'Sedikit es',
                    'modifier_ids' => $modifier ? [$modifier->id] : [],
                ],
            ],
        ];

        $order = $this->orderService->createOrder($this->cashier, $this->shift, $payload);

        $this->assertNotNull($order->id);
        $this->assertEquals(Order::STATUS_AWAITING_PAYMENT, $order->status);
        $this->assertEquals('Dimas', $order->customer_name);
        $this->assertStringStartsWith('TP-', $order->call_number);
        $this->assertCount(1, $order->items);

        $item = $order->items->first();
        $this->assertEquals($product->name, $item->product_name);
        $this->assertEquals(2, $item->quantity);
        $this->assertEquals($item->final_unit_price * 2, $item->subtotal);

        // Verify status history
        $this->assertCount(1, $order->statusHistories);
        $this->assertEquals(Order::STATUS_AWAITING_PAYMENT, $order->statusHistories->first()->to_status);
    }

    public function test_cannot_order_sold_out_product(): void
    {
        $product = Product::first();
        $product->update(['is_sold_out' => true]);

        $payload = [
            'order_type' => Order::TYPE_TAKEAWAY,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                ],
            ],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sedang habis (sold out)');

        $this->orderService->createOrder($this->cashier, $this->shift, $payload);
    }

    public function test_cannot_create_order_when_shift_is_closed(): void
    {
        $this->shift->update(['status' => 'closed', 'closed_at' => now(), 'closing_cash' => 200000]);

        $product = Product::where('is_sold_out', false)->first();

        $payload = [
            'order_type' => Order::TYPE_DINE_IN,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Shift kasir sudah ditutup');

        $this->orderService->createOrder($this->cashier, $this->shift, $payload);
    }

    public function test_idempotency_prevents_duplicate_orders(): void
    {
        $product = Product::where('is_sold_out', false)->first();
        $uuid = 'test-uuid-idempotency-key';

        $payload = [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => $uuid,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ];

        $order1 = $this->orderService->createOrder($this->cashier, $this->shift, $payload);
        $order2 = $this->orderService->createOrder($this->cashier, $this->shift, $payload);

        $this->assertEquals($order1->id, $order2->id);
        $this->assertEquals(1, Order::where('idempotency_key', $uuid)->count());
    }

    public function test_order_lifecycle_transitions(): void
    {
        $product = Product::where('is_sold_out', false)->first();
        $payload = [
            'order_type' => Order::TYPE_DINE_IN,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];

        $order = $this->orderService->createOrder($this->cashier, $this->shift, $payload);

        // awaiting_payment -> paid
        $order = $this->orderService->advanceStatus($order, Order::STATUS_PAID, $this->cashier);
        $this->assertEquals(Order::STATUS_PAID, $order->status);

        // paid -> queued
        $order = $this->orderService->advanceStatus($order, Order::STATUS_QUEUED, $this->cashier);
        $this->assertEquals(Order::STATUS_QUEUED, $order->status);

        // queued -> preparing
        $order = $this->orderService->advanceStatus($order, Order::STATUS_PREPARING, $this->cashier);
        $this->assertEquals(Order::STATUS_PREPARING, $order->status);

        // preparing -> ready
        $order = $this->orderService->advanceStatus($order, Order::STATUS_READY, $this->cashier);
        $this->assertEquals(Order::STATUS_READY, $order->status);

        // ready -> completed
        $order = $this->orderService->advanceStatus($order, Order::STATUS_COMPLETED, $this->cashier);
        $this->assertEquals(Order::STATUS_COMPLETED, $order->status);

        // Completed cannot transition further
        $this->expectException(InvalidArgumentException::class);
        $this->orderService->advanceStatus($order, Order::STATUS_QUEUED, $this->cashier);
    }

    public function test_only_owner_can_cancel_order(): void
    {
        $product = Product::where('is_sold_out', false)->first();
        $payload = [
            'order_type' => Order::TYPE_DINE_IN,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];

        $order = $this->orderService->createOrder($this->cashier, $this->shift, $payload);

        // Cashier cannot cancel
        try {
            $this->orderService->cancelOrder($order, $this->cashier, 'Alasan kasir');
            $this->fail('Cashier should not be able to cancel order.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Hanya Pemilik (Owner)', $e->getMessage());
        }

        // Owner can cancel
        $order = $this->orderService->cancelOrder($order, $this->owner, 'Pelanggan salah pesan');
        $this->assertEquals(Order::STATUS_CANCELLED, $order->status);
        $this->assertEquals('Pelanggan salah pesan', $order->cancellation_reason);
        $this->assertEquals($this->owner->id, $order->cancelled_by_user_id);
    }

    public function test_business_day_boundary_rollover(): void
    {
        // Suppose business day starts at 06:00
        $shopSetting = ShopSetting::where('is_active', true)->first();
        $shopSetting->update(['business_day_start_time' => '06:00:00']);

        // 02:00 AM on Nov 15th belongs to Nov 14th business day
        $midnightTime = Carbon::parse('2026-11-15 02:00:00', 'Asia/Jakarta');
        $this->assertEquals('2026-11-14', $this->orderService->calculateBusinessDate($midnightTime));

        // 07:00 AM on Nov 15th belongs to Nov 15th business day
        $morningTime = Carbon::parse('2026-11-15 07:00:00', 'Asia/Jakarta');
        $this->assertEquals('2026-11-15', $this->orderService->calculateBusinessDate($morningTime));
    }
}
