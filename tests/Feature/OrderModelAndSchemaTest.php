<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderModelAndSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    /**
     * 1. Order tables exist and can be populated via factories.
     */
    public function test_order_and_items_can_be_created_with_relationships(): void
    {
        $cashier = User::factory()->cashier()->create();
        $shift = Shift::factory()->create(['user_id' => $cashier->id]);
        $product = Product::factory()->create(['base_price' => 20000]);

        $order = Order::factory()->create([
            'user_id' => $cashier->id,
            'shift_id' => $shift->id,
            'order_type' => 'dine_in',
            'status' => 'awaiting_payment',
            'subtotal' => 20000,
            'total_amount' => 20000,
        ]);

        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'unit_price' => 20000,
            'final_unit_price' => 20000,
            'quantity' => 1,
            'subtotal' => 20000,
        ]);

        $modifier = OrderItemModifier::create([
            'order_item_id' => $item->id,
            'group_name_id' => 'Level Gula',
            'group_name_en' => 'Sugar Level',
            'modifier_name_id' => 'Normal Gula (100%)',
            'modifier_name_en' => 'Normal Sugar (100%)',
            'unit_price' => 0,
        ]);

        $history = OrderStatusHistory::create([
            'order_id' => $order->id,
            'from_status' => 'draft',
            'to_status' => 'awaiting_payment',
            'user_id' => $cashier->id,
            'notes' => 'Pesanan dibuat di kasir',
        ]);

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertDatabaseHas('order_items', ['id' => $item->id]);
        $this->assertDatabaseHas('order_item_modifiers', ['id' => $modifier->id]);
        $this->assertDatabaseHas('order_status_histories', ['id' => $history->id]);

        // Relationship assertions
        $this->assertCount(1, $order->items);
        $this->assertCount(1, $order->statusHistories);
        $this->assertEquals($shift->id, $order->shift->id);
        $this->assertEquals($cashier->id, $order->user->id);
        $this->assertCount(1, $item->modifiers);
        $this->assertEquals($order->id, $item->order->id);
        $this->assertEquals($item->id, $modifier->orderItem->id);
    }

    /**
     * 2. Deleting an order cascades to its items, modifiers, and status histories.
     */
    public function test_order_force_delete_cascades_to_items_and_histories(): void
    {
        $order = Order::factory()->create();
        $item = OrderItem::factory()->create(['order_id' => $order->id]);
        $modifier = OrderItemModifier::create([
            'order_item_id' => $item->id,
            'group_name_id' => 'Es',
            'modifier_name_id' => 'Normal',
            'unit_price' => 0,
        ]);
        $history = OrderStatusHistory::create([
            'order_id' => $order->id,
            'to_status' => 'awaiting_payment',
            'user_id' => $order->user_id,
        ]);

        $orderId = $order->id;
        $itemId = $item->id;
        $modId = $modifier->id;
        $histId = $history->id;

        $order->forceDelete();

        $this->assertDatabaseMissing('orders', ['id' => $orderId]);
        $this->assertDatabaseMissing('order_items', ['id' => $itemId]);
        $this->assertDatabaseMissing('order_item_modifiers', ['id' => $modId]);
        $this->assertDatabaseMissing('order_status_histories', ['id' => $histId]);
    }

    /**
     * 3. Deleting a catalog product sets order_items.product_id to null without destroying snapshot.
     */
    public function test_deleting_catalog_product_preserves_order_item_snapshots(): void
    {
        $product = Product::factory()->create();
        $order = Order::factory()->create();
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name_id' => 'Es Kopi Susu Aren',
            'product_name_en' => 'Iced Palm Sugar Milk Coffee',
            'unit_price' => 22000,
        ]);

        // Force delete product
        $product->forceDelete();

        $item->refresh();
        $this->assertNull($item->product_id);
        $this->assertEquals('Es Kopi Susu Aren', $item->product_name_id);
        $this->assertEquals('Iced Palm Sugar Milk Coffee', $item->product_name_en);
        $this->assertEquals(22000, $item->unit_price);
    }

    /**
     * 4. Model immutability prevents editing financial fields on paid orders.
     */
    public function test_financial_fields_cannot_be_modified_on_paid_or_completed_orders(): void
    {
        $order = Order::factory()->paid()->create([
            'subtotal' => 45000,
            'total_amount' => 45000,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tidak dapat diubah setelah pesanan diproses');

        $order->update(['total_amount' => 10000]);
    }

    /**
     * 5. Localized accessors on order items resolve according to application locale.
     */
    public function test_localized_accessors_on_order_items(): void
    {
        $item = OrderItem::factory()->create([
            'product_name_id' => 'Teh Manis',
            'product_name_en' => 'Sweet Tea',
            'variant_name_id' => 'Besar',
            'variant_name_en' => 'Large',
        ]);

        // Default ID locale
        app()->setLocale('id');
        $this->assertEquals('Teh Manis', $item->product_name);
        $this->assertEquals('Besar', $item->variant_name);
        $this->assertEquals('Teh Manis (Besar)', $item->full_name);

        // Switch to EN locale
        app()->setLocale('en');
        $this->assertEquals('Sweet Tea', $item->product_name);
        $this->assertEquals('Large', $item->variant_name);
        $this->assertEquals('Sweet Tea (Large)', $item->full_name);
    }
}
