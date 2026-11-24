<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    public function definition(): array
    {
        $balanceBefore = fake()->randomFloat(3, 0, 5000);
        $qty = fake()->randomFloat(3, 0.001, 500);
        $balanceAfter = round($balanceBefore + $qty, 3);

        return [
            'ulid' => Str::ulid(),
            'branch_id' => null,
            'ingredient_id' => Ingredient::factory(),
            'user_id' => User::factory(),
            'movement_type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => number_format($qty, 3, '.', ''),
            'balance_before' => number_format($balanceBefore, 3, '.', ''),
            'balance_after' => number_format($balanceAfter, 3, '.', ''),
            'unit_cost' => fake()->numberBetween(0, 50000),
            'reference_type' => null,
            'reference_id' => null,
            'idempotency_key' => null,
            'notes' => null,
        ];
    }
}
