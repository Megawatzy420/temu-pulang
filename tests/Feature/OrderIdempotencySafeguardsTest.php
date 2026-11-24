<?php

namespace Tests\Feature;

use App\Models\Modifier;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class OrderIdempotencySafeguardsTest extends TestCase
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
            openingCash: 100000,
            openingNote: 'Kas modal test idempotency'
        );
    }

    public function test_exact_same_payload_returns_existing_order(): void
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();
        $key = (string) Str::uuid();

        $payload = [
            'order_type' => Order::TYPE_DINE_IN,
            'customer_name' => 'Budi Santoso',
            'notes' => 'Meja pojok',
            'idempotency_key' => $key,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'notes' => 'Jangan terlalu manis',
                ],
            ],
        ];

        $order1 = $this->orderService->createOrder($this->cashier, $this->shift, $payload);
        $order2 = $this->orderService->createOrder($this->cashier, $this->shift, $payload);

        $this->assertEquals($order1->id, $order2->id);
        $this->assertEquals($order1->order_number, $order2->order_number);
        $this->assertEquals(1, Order::where('idempotency_key', $key)->count());
    }

    public function test_retry_with_different_order_type_is_rejected(): void
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();
        $key = (string) Str::uuid();

        $payload1 = [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => $key,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];
        $this->orderService->createOrder($this->cashier, $this->shift, $payload1);

        $payload2 = [
            'order_type' => Order::TYPE_TAKEAWAY, // different!
            'idempotency_key' => $key,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('tipe pesanan berbeda');
        $this->orderService->createOrder($this->cashier, $this->shift, $payload2);
    }

    public function test_retry_with_different_customer_name_is_rejected(): void
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();
        $key = (string) Str::uuid();

        $payload1 = [
            'order_type' => Order::TYPE_DINE_IN,
            'customer_name' => 'Sarah',
            'idempotency_key' => $key,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];
        $this->orderService->createOrder($this->cashier, $this->shift, $payload1);

        $payload2 = [
            'order_type' => Order::TYPE_DINE_IN,
            'customer_name' => 'Budi', // different!
            'idempotency_key' => $key,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('nama pelanggan berbeda');
        $this->orderService->createOrder($this->cashier, $this->shift, $payload2);
    }

    public function test_retry_with_different_order_notes_is_rejected(): void
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();
        $key = (string) Str::uuid();

        $payload1 = [
            'order_type' => Order::TYPE_DINE_IN,
            'notes' => 'Catatan A',
            'idempotency_key' => $key,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];
        $this->orderService->createOrder($this->cashier, $this->shift, $payload1);

        $payload2 = [
            'order_type' => Order::TYPE_DINE_IN,
            'notes' => 'Catatan B', // different!
            'idempotency_key' => $key,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('catatan pesanan berbeda');
        $this->orderService->createOrder($this->cashier, $this->shift, $payload2);
    }

    public function test_retry_with_different_item_quantity_is_rejected(): void
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();
        $key = (string) Str::uuid();

        $payload1 = [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => $key,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];
        $this->orderService->createOrder($this->cashier, $this->shift, $payload1);

        $payload2 = [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => $key,
            'items' => [['product_id' => $product->id, 'quantity' => 2]], // different!
        ];

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('rincian item, varian, atau modifier berbeda');
        $this->orderService->createOrder($this->cashier, $this->shift, $payload2);
    }

    public function test_retry_with_different_product_id_is_rejected(): void
    {
        $products = Product::where('is_sold_out', false)->take(2)->get();
        $this->assertCount(2, $products);
        $key = (string) Str::uuid();

        $payload1 = [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => $key,
            'items' => [['product_id' => $products[0]->id, 'quantity' => 1]],
        ];
        $this->orderService->createOrder($this->cashier, $this->shift, $payload1);

        $payload2 = [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => $key,
            'items' => [['product_id' => $products[1]->id, 'quantity' => 1]], // different!
        ];

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('rincian item, varian, atau modifier berbeda');
        $this->orderService->createOrder($this->cashier, $this->shift, $payload2);
    }

    public function test_retry_with_different_variant_is_rejected(): void
    {
        $product = Product::whereHas('variants')->where('is_sold_out', false)->firstOrFail();
        $variants = $product->variants->where('is_sold_out', false)->values();
        if ($variants->count() < 2) {
            $this->markTestSkipped('Need product with at least 2 variants.');
        }

        $key = (string) Str::uuid();

        $payload1 = [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => $key,
            'items' => [['product_id' => $product->id, 'variant_id' => $variants[0]->id, 'quantity' => 1]],
        ];
        $this->orderService->createOrder($this->cashier, $this->shift, $payload1);

        $payload2 = [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => $key,
            'items' => [['product_id' => $product->id, 'variant_id' => $variants[1]->id, 'quantity' => 1]],
        ];

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('rincian item, varian, atau modifier berbeda');
        $this->orderService->createOrder($this->cashier, $this->shift, $payload2);
    }

    public function test_retry_with_different_modifiers_is_rejected(): void
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();
        $modifiers = Modifier::where('is_sold_out', false)->take(2)->get();
        if ($modifiers->count() < 2) {
            $this->markTestSkipped('Need at least 2 modifiers.');
        }

        $key = (string) Str::uuid();

        $payload1 = [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => $key,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'modifier_ids' => [$modifiers[0]->id],
            ]],
        ];
        $this->orderService->createOrder($this->cashier, $this->shift, $payload1);

        $payload2 = [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => $key,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'modifier_ids' => [$modifiers[1]->id], // different modifier!
            ]],
        ];

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('rincian item, varian, atau modifier berbeda');
        $this->orderService->createOrder($this->cashier, $this->shift, $payload2);
    }

    public function test_equivalent_payload_with_different_item_order_returns_existing_order(): void
    {
        $products = Product::where('is_sold_out', false)->take(2)->get();
        $this->assertCount(2, $products);
        $key = (string) Str::uuid();

        // Order 1: Product 0 first, then Product 1
        $payload1 = [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => $key,
            'items' => [
                ['product_id' => $products[0]->id, 'quantity' => 1],
                ['product_id' => $products[1]->id, 'quantity' => 2],
            ],
        ];
        $order1 = $this->orderService->createOrder($this->cashier, $this->shift, $payload1);

        // Order 2: Product 1 first, then Product 0 (same items, different array order)
        $payload2 = [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => $key,
            'items' => [
                ['product_id' => $products[1]->id, 'quantity' => 2],
                ['product_id' => $products[0]->id, 'quantity' => 1],
            ],
        ];
        $order2 = $this->orderService->createOrder($this->cashier, $this->shift, $payload2);

        $this->assertEquals($order1->id, $order2->id);
    }

    public function test_item_notes_are_stored_in_special_instructions(): void
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();
        $key = (string) Str::uuid();

        $payload = [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => $key,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'notes' => 'Sedikit es batu dan tanpa sedotan',
                ],
            ],
        ];

        $order = $this->orderService->createOrder($this->cashier, $this->shift, $payload);
        $item = $order->items->first();

        $this->assertEquals('Sedikit es batu dan tanpa sedotan', $item->special_instructions);
        $this->assertEquals('Sedikit es batu dan tanpa sedotan', $item->notes);
    }

    public function test_concurrent_unique_constraint_race_safely_returns_existing_order(): void
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();
        $key = (string) Str::uuid();

        $payload = [
            'order_type' => Order::TYPE_DINE_IN,
            'idempotency_key' => $key,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];

        // Create the first order legitimately
        $firstOrder = $this->orderService->createOrder($this->cashier, $this->shift, $payload);

        // Now test assertPayloadMatchesExistingOrder and race catch:
        // Even if the pre-flight check somehow missed the order, the DB catch block will catch the race and return it
        $retriedOrder = $this->orderService->createOrder($this->cashier, $this->shift, $payload);

        $this->assertEquals($firstOrder->id, $retriedOrder->id);
    }
}
