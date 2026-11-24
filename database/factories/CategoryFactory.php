<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
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
            'sort_order' => fake()->numberBetween(0, 50),
            'is_active' => true,
        ];
    }

    /**
     * Configure factory to automatically seed EN and ID translations.
     */
    public function withTranslations(?string $nameEn = null, ?string $nameId = null): static
    {
        return $this->afterCreating(function (Category $category) use ($nameEn, $nameId) {
            $category->translations()->create([
                'locale' => 'en',
                'name' => $nameEn ?? ucfirst($category->slug),
                'description' => 'Description in English',
            ]);

            $category->translations()->create([
                'locale' => 'id',
                'name' => $nameId ?? 'Kategori '.ucfirst($category->slug),
                'description' => 'Deskripsi dalam Bahasa Indonesia',
            ]);
        });
    }
}
