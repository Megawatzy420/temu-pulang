<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\IngredientCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ingredient>
 */
class IngredientFactory extends Factory
{
    protected $model = Ingredient::class;

    public function definition(): array
    {
        return [
            'category_id' => IngredientCategory::factory(),
            'sku' => 'ING-'.fake()->unique()->numerify('######'),
            'name' => fake()->words(2, true),
            'unit' => fake()->randomElement(['g', 'ml', 'pcs', 'kg', 'L']),
            'cost_per_unit' => fake()->numberBetween(100, 50000),
            'min_stock_alert' => number_format(fake()->randomFloat(3, 0, 1000), 3, '.', ''),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
