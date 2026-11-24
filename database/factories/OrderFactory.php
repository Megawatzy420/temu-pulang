<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $businessDate = now('Asia/Jakarta')->toDateString();
        $seq = fake()->numberBetween(1, 500);

        return [
            'ulid' => (string) Str::ulid(),
            'order_number' => 'ORD-'.str_replace('-', '', $businessDate).'-'.sprintf('%04d', $seq),
            'daily_order_number' => sprintf('TP-%03d', $seq),
            'daily_sequence' => $seq,
            'business_date' => $businessDate,
            'branch_id' => null,
            'shift_id' => Shift::factory(),
            'user_id' => User::factory(),
            'order_type' => fake()->randomElement(['dine_in', 'takeaway']),
            'status' => 'awaiting_payment',
            'subtotal' => 50000,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'service_charge_rate' => 0,
            'service_charge_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 50000,
            'customer_name' => fake()->name(),
            'idempotency_key' => (string) Str::uuid(),
            'notes' => null,
            'cancellation_reason' => null,
            'cancelled_by_user_id' => null,
            'cancelled_at' => null,
            'paid_at' => null,
            'completed_at' => null,
        ];
    }

    /**
     * State for paid orders.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }

    /**
     * State for kitchen queued orders.
     */
    public function queued(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'queued',
            'paid_at' => now()->subMinutes(5),
        ]);
    }

    /**
     * State for preparing orders.
     */
    public function preparing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'preparing',
            'paid_at' => now()->subMinutes(10),
        ]);
    }

    /**
     * State for completed orders.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'paid_at' => now()->subMinutes(20),
            'completed_at' => now(),
        ]);
    }

    /**
     * State for cancelled orders.
     */
    public function cancelled(?string $reason = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
            'cancellation_reason' => $reason ?? 'Pelanggan membatalkan pesanan',
            'cancelled_at' => now(),
        ]);
    }
}
