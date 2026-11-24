<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\IngredientBalance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngredientBalance>
 */
class IngredientBalanceFactory extends Factory
{
    protected $model = IngredientBalance::class;

    public function definition(): array
    {
        return [
            'branch_id' => null,
            'ingredient_id' => Ingredient::factory(),
            'current_stock' => number_format(fake()->randomFloat(3, 0, 10000), 3, '.', ''),
        ];
    }
}
