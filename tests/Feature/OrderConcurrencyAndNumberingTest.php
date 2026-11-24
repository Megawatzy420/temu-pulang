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
use Tests\TestCase;

class OrderConcurrencyAndNumberingTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected Shift $shift;

    protected OrderService $orderService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();
        $this->orderService = app(OrderService::class);
        $this->shift = app(ShiftService::class)->openShift(
            user: $this->cashier,
            openingCash: 150000,
            openingNote: 'Kas modal counter test'
        );
    }

    public function test_daily_sequence_increments_consecutively_within_business_day(): void
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();

        $order1 = $this->orderService->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $order2 = $this->orderService->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_TAKEAWAY,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $order3 = $this->orderService->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $this->assertEquals(1, $order1->daily_sequence);
        $this->assertEquals('TP-001', $order1->call_number);

        $this->assertEquals(2, $order2->daily_sequence);
        $this->assertEquals('TP-002', $order2->call_number);

        $this->assertEquals(3, $order3->daily_sequence);
        $this->assertEquals('TP-003', $order3->call_number);
    }

    public function test_sequence_resets_on_new_business_day(): void
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();

        // Day 1
        $orderDay1 = $this->orderService->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $this->assertEquals('TP-001', $orderDay1->call_number);

        // Simulate next day sequence calculation
        $tomorrowDate = Carbon::now()->addDay()->toDateString();
        $seqData = $this->orderService->getNextDailySequence($tomorrowDate, $this->shift->branch_id);

        $this->assertEquals(1, $seqData['sequence']);
        $this->assertEquals('TP-001', $seqData['call_number']);
        $this->assertStringContainsString(str_replace('-', '', $tomorrowDate), $seqData['order_number']);
    }

    public function test_financial_calculations_with_shop_tax_and_service(): void
    {
        $shopSetting = ShopSetting::where('is_active', true)->first();
        // 10% tax (1000 bp) and 5% service charge (500 bp)
        $shopSetting->update([
            'tax_rate' => 1000,
            'service_charge_rate' => 500,
        ]);

        $product = Product::where('is_sold_out', false)->firstOrFail();
        $product->update(['base_price' => 100000]);

        $order = $this->orderService->createOrder($this->cashier, $this->shift, [
            'order_type' => Order::TYPE_DINE_IN,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $this->assertEquals(100000, $order->subtotal);
        $this->assertEquals(5000, $order->service_charge_amount); // 5% of 100.000
        $this->assertEquals(10500, $order->tax_amount); // 10% of (100.000 + 5.000)
        $this->assertEquals(115500, $order->total_amount);
    }
}
