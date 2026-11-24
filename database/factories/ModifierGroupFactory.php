<?php

namespace Database\Factories;

use App\Models\ModifierGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ModifierGroup>
 */
class ModifierGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'slug' => Str::slug($name),
            'min_selections' => 0,
            'max_selections' => 1,
            'is_required' => false,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 10),
        ];
    }

    /**
     * Attach translations.
     */
    public function withTranslations(?string $nameEn = null, ?string $nameId = null): static
    {
        return $this->afterCreating(function (ModifierGroup $group) use ($nameEn, $nameId) {
            $group->translations()->create([
                'locale' => 'en',
                'name' => $nameEn ?? ucfirst($group->slug),
                'description' => 'Select your preference',
            ]);

            $group->translations()->create([
                'locale' => 'id',
                'name' => $nameId ?? 'Pilihan '.ucfirst($group->slug),
                'description' => 'Pilih sesuai selera Anda',
            ]);
        });
    }

    /**
     * Indicate that the modifier group is required.
     */
    public function required(int $min = 1, int $max = 1): static
    {
        return $this->state(fn (array $attributes) => [
            'is_required' => true,
            'min_selections' => $min,
            'max_selections' => $max,
        ]);
    }
}
