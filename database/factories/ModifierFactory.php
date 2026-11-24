<?php

namespace Database\Factories;

use App\Models\Modifier;
use App\Models\ModifierGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Modifier>
 */
class ModifierFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'modifier_group_id' => ModifierGroup::factory(),
            'price' => fake()->randomElement([0, 3000, 5000, 10000]),
            'is_active' => true,
            'is_sold_out' => false,
            'sort_order' => fake()->numberBetween(0, 10),
        ];
    }

    /**
     * Attach translations.
     */
    public function withTranslations(?string $nameEn = null, ?string $nameId = null): static
    {
        return $this->afterCreating(function (Modifier $modifier) use ($nameEn, $nameId) {
            $modifier->translations()->create([
                'locale' => 'en',
                'name' => $nameEn ?? 'Modifier Option',
            ]);

            $modifier->translations()->create([
                'locale' => 'id',
                'name' => $nameId ?? 'Opsi Tambahan',
            ]);
        });
    }

    /**
     * Free modifier (price = 0).
     */
    public function free(): static
    {
        return $this->state(fn (array $attributes) => [
            'price' => 0,
        ]);
    }

    /**
     * Paid modifier.
     */
    public function paid(int $price = 5000): static
    {
        return $this->state(fn (array $attributes) => [
            'price' => $price,
        ]);
    }
}
