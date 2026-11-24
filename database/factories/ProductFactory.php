<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'sku' => 'PRD-'.fake()->unique()->numerify('#####'),
            'base_price' => fake()->numberBetween(10, 80) * 1000, // 10,000 to 80,000 IDR
            'image_path' => null,
            'has_variants' => false,
            'is_active' => true,
            'is_sold_out' => false,
            'dine_in_available' => true,
            'takeaway_available' => true,
            'sort_order' => fake()->numberBetween(0, 50),
        ];
    }

    /**
     * Configure factory to automatically attach translations.
     */
    public function withTranslations(?string $nameEn = null, ?string $nameId = null): static
    {
        return $this->afterCreating(function (Product $product) use ($nameEn, $nameId) {
            $product->translations()->create([
                'locale' => 'en',
                'name' => $nameEn ?? 'Product '.$product->sku,
                'description' => 'Delicious food item in English',
            ]);

            $product->translations()->create([
                'locale' => 'id',
                'name' => $nameId ?? 'Produk '.$product->sku,
                'description' => 'Makanan lezat dalam Bahasa Indonesia',
            ]);
        });
    }

    /**
     * Mark product as sold out.
     */
    public function soldOut(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_sold_out' => true,
        ]);
    }

    /**
     * Mark product as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
