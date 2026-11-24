<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\Modifier;
use App\Models\ModifierRecipeItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModifierRecipeItem>
 */
class ModifierRecipeItemFactory extends Factory
{
    protected $model = ModifierRecipeItem::class;

    public function definition(): array
    {
        return [
            'modifier_id' => Modifier::factory(),
            'ingredient_id' => Ingredient::factory(),
            'action' => ModifierRecipeItem::ACTION_ADD,
            'replaces_ingredient_id' => null,
            'quantity' => number_format(fake()->randomFloat(3, 0.001, 200), 3, '.', ''),
        ];
    }

    public function replace(?int $replacesIngredientId = null): static
    {
        return $this->state([
            'action' => ModifierRecipeItem::ACTION_REPLACE,
            'replaces_ingredient_id' => $replacesIngredientId,
        ]);
    }
}
