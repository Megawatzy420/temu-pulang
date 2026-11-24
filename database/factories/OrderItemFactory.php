<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $price = 25000;
        $qty = fake()->numberBetween(1, 3);

        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'product_variant_id' => null,
            'sku' => 'PRD-TEST-'.fake()->unique()->numerify('###'),
            'product_name_id' => 'Kopi Tubruk',
            'product_name_en' => 'Traditional Brewed Coffee',
            'variant_name_id' => null,
            'variant_name_en' => null,
            'unit_price' => $price,
            'modifiers_unit_price' => 0,
            'final_unit_price' => $price,
            'quantity' => $qty,
            'subtotal' => $price * $qty,
            'special_instructions' => null,
        ];
    }
}
