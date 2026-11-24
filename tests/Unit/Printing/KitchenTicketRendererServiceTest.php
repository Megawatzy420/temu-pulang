<?php

namespace Tests\Unit\Printing;

use App\Models\Modifier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Services\Printing\KitchenTicketRendererService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class KitchenTicketRendererServiceTest extends TestCase
{
    use RefreshDatabase;

    protected KitchenTicketRendererService $renderer;

    protected User $cashier;

    protected Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->renderer = new KitchenTicketRendererService;

        $this->cashier = User::where('email', 'cashier@temupulang.local')->first()
            ?? User::factory()->create(['name' => 'Kasir Test']);

        $this->shift = Shift::factory()->create([
            'user_id' => $this->cashier->id,
        ]);
    }

    public function test_renders_kitchen_ticket_without_financial_data(): void
    {
        $order = Order::create([
            'ulid' => (string) Str::ulid(),
            'order_number' => 'ORD-20261114-0010',
            'daily_order_number' => '0010',
            'daily_sequence' => 10,
            'business_date' => '2026-11-14',
            'shift_id' => $this->shift->id,
            'user_id' => $this->cashier->id,
            'order_type' => Order::TYPE_DINE_IN,
            'status' => Order::STATUS_PAID,
            'subtotal' => 75000,
            'tax_rate' => 1000,
            'tax_amount' => 7500,
            'service_charge_rate' => 0,
            'service_charge_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 82500,
            'customer_name' => 'Meja 5',
            'notes' => 'Antar bersamaan',
            'paid_at' => now('Asia/Jakarta'),
        ]);

        $product = Product::first();
        $modifier = Modifier::with('group')->first();

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product?->id,
            'sku' => $product?->sku ?? 'PRD-TEST-002',
            'product_name_id' => 'Nasi Goreng Spesial',
            'product_name_en' => 'Special Fried Rice',
            'variant_name_id' => 'Pedas Sedang',
            'variant_name_en' => 'Medium Spicy',
            'unit_price' => 35000,
            'modifiers_unit_price' => 5000,
            'final_unit_price' => 40000,
            'quantity' => 2,
            'subtotal' => 80000,
            'special_instructions' => 'Jangan pakai daun bawang',
        ]);

        if ($modifier) {
            $item->modifiers()->create([
                'modifier_id' => $modifier->id,
                'modifier_group_id' => $modifier->modifier_group_id,
                'group_name_id' => 'Telur',
                'group_name_en' => 'Egg',
                'modifier_name_id' => 'Telur Mata Sapi',
                'modifier_name_en' => 'Sunny Side Up',
                'unit_price' => 5000,
            ]);
        }

        $bytes = $this->renderer->render($order->fresh(['items.modifiers']));

        // Verification
        $this->assertStringContainsString('PESANAN DAPUR / BAR', $bytes);
        $this->assertStringContainsString('No: ORD-20261114-0010', $bytes);
        $this->assertStringContainsString('Dine-in', $bytes);
        $this->assertStringContainsString('2x Nasi Goreng Spesial (Pedas Sedang)', $bytes);
        $this->assertStringContainsString('Telur Mata Sapi', $bytes);
        $this->assertStringContainsString('Jangan pakai daun bawang', $bytes);
        $this->assertStringContainsString('Catatan Pesanan:', $bytes);
        $this->assertStringContainsString('Antar bersamaan', $bytes);

        // Crucial: No financial data or prices should ever appear on kitchen tickets
        $this->assertStringNotContainsString('Rp', $bytes);
        $this->assertStringNotContainsString('Subtotal', $bytes);
        $this->assertStringNotContainsString('TOTAL', $bytes);
        $this->assertStringNotContainsString('Tunai', $bytes);
    }
}
