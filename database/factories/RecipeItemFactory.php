<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\Product;
use App\Models\RecipeItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecipeItem>
 */
class RecipeItemFactory extends Factory
{
    protected $model = RecipeItem::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'product_variant_id' => null,
            'ingredient_id' => Ingredient::factory(),
            'quantity' => number_format(fake()->randomFloat(3, 0.001, 500), 3, '.', ''),
        ];
    }
}
