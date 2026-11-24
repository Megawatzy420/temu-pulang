<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class ProductCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'slug' => 'beverages',
                'sort_order' => 1,
                'is_active' => true,
                'translations' => [
                    'en' => [
                        'name' => 'Beverages',
                        'description' => 'Coffee, tea, and handcrafted iced drinks',
                    ],
                    'id' => [
                        'name' => 'Minuman',
                        'description' => 'Kopi, teh, dan aneka minuman segar racikan',
                    ],
                ],
            ],
            [
                'slug' => 'food',
                'sort_order' => 2,
                'is_active' => true,
                'translations' => [
                    'en' => [
                        'name' => 'Main Courses',
                        'description' => 'Hearty rice bowls and savory mains',
                    ],
                    'id' => [
                        'name' => 'Makanan Utama',
                        'description' => 'Menu hidangan utama mengenyangkan dan lezat',
                    ],
                ],
            ],
            [
                'slug' => 'snacks',
                'sort_order' => 3,
                'is_active' => true,
                'translations' => [
                    'en' => [
                        'name' => 'Snacks & Bites',
                        'description' => 'Finger food and light bites',
                    ],
                    'id' => [
                        'name' => 'Makanan Ringan',
                        'description' => 'Camilan dan makanan pembuka renyah',
                    ],
                ],
            ],
        ];

        foreach ($categories as $catData) {
            $category = Category::updateOrCreate(
                ['slug' => $catData['slug']],
                [
                    'sort_order' => $catData['sort_order'],
                    'is_active' => $catData['is_active'],
                ]
            );

            foreach ($catData['translations'] as $locale => $translation) {
                $category->translations()->updateOrCreate(
                    ['locale' => $locale],
                    [
                        'name' => $translation['name'],
                        'description' => $translation['description'],
                    ]
                );
            }
        }
    }
}
