<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(['has_variants' => true]),
            'sku' => 'VAR-'.fake()->unique()->numerify('#####'),
            'price' => fake()->numberBetween(15, 60) * 1000,
            'is_active' => true,
            'is_sold_out' => false,
            'sort_order' => fake()->numberBetween(0, 10),
        ];
    }

    /**
     * Attach variant translations.
     */
    public function withTranslations(?string $nameEn = null, ?string $nameId = null): static
    {
        return $this->afterCreating(function (ProductVariant $variant) use ($nameEn, $nameId) {
            $variant->translations()->create([
                'locale' => 'en',
                'name' => $nameEn ?? 'Regular Size',
            ]);

            $variant->translations()->create([
                'locale' => 'id',
                'name' => $nameId ?? 'Ukuran Biasa',
            ]);
        });
    }

    /**
     * Mark variant as sold out.
     */
    public function soldOut(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_sold_out' => true,
        ]);
    }

    /**
     * Mark variant as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
