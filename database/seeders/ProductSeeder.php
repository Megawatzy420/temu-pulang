<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $beverages = Category::where('slug', 'beverages')->first();
        $food = Category::where('slug', 'food')->first();
        $snacks = Category::where('slug', 'snacks')->first();

        // 1. French Fries (No variants)
        $frenchFries = Product::updateOrCreate(
            ['sku' => 'PRD-FF-001'],
            [
                'category_id' => $snacks?->id,
                'base_price' => 20000, // Rp 20.000
                'image_path' => 'products/french-fries.jpg',
                'has_variants' => false,
                'is_active' => true,
                'is_sold_out' => false,
                'dine_in_available' => true,
                'takeaway_available' => true,
                'sort_order' => 1,
            ]
        );
        $frenchFries->translations()->updateOrCreate(
            ['locale' => 'en'],
            ['name' => 'French Fries', 'description' => 'Crispy golden potato fries served with chili sauce']
        );
        $frenchFries->translations()->updateOrCreate(
            ['locale' => 'id'],
            ['name' => 'Kentang Goreng', 'description' => 'Kentang goreng renyah disajikan dengan saus sambal khas']
        );

        // 2. Coffee (No variants)
        $coffee = Product::updateOrCreate(
            ['sku' => 'PRD-CF-001'],
            [
                'category_id' => $beverages?->id,
                'base_price' => 18000, // Rp 18.000
                'image_path' => 'products/coffee.jpg',
                'has_variants' => false,
                'is_active' => true,
                'is_sold_out' => false,
                'dine_in_available' => true,
                'takeaway_available' => true,
                'sort_order' => 2,
            ]
        );
        $coffee->translations()->updateOrCreate(
            ['locale' => 'en'],
            ['name' => 'Temu Coffee', 'description' => 'Signature house blend black coffee brewed fresh']
        );
        $coffee->translations()->updateOrCreate(
            ['locale' => 'id'],
            ['name' => 'Kopi Temu', 'description' => 'Kopi hitam racikan khas Temu Pulang diseduh segar']
        );

        // 3. Lemon Tea (Has variants: Regular & Large)
        $lemonTea = Product::updateOrCreate(
            ['sku' => 'PRD-LT-001'],
            [
                'category_id' => $beverages?->id,
                'base_price' => 12000, // Rp 12.000 base
                'image_path' => 'products/lemon-tea.jpg',
                'has_variants' => true,
                'is_active' => true,
                'is_sold_out' => false,
                'dine_in_available' => true,
                'takeaway_available' => true,
                'sort_order' => 3,
            ]
        );
        $lemonTea->translations()->updateOrCreate(
            ['locale' => 'en'],
            ['name' => 'Lemon Tea', 'description' => 'Refreshing iced black tea with fresh lemon slices']
        );
        $lemonTea->translations()->updateOrCreate(
            ['locale' => 'id'],
            ['name' => 'Teh Lemon', 'description' => 'Teh hitam segar dingin dengan perasan dan irisan lemon asli']
        );

        // Lemon Tea Variants:
        $ltRegular = ProductVariant::updateOrCreate(
            ['sku' => 'PRD-LT-001-REG'],
            [
                'product_id' => $lemonTea->id,
                'price' => 12000, // Rp 12.000
                'is_active' => true,
                'is_sold_out' => false,
                'sort_order' => 1,
            ]
        );
        $ltRegular->translations()->updateOrCreate(['locale' => 'en'], ['name' => 'Regular']);
        $ltRegular->translations()->updateOrCreate(['locale' => 'id'], ['name' => 'Biasa']);

        $ltLarge = ProductVariant::updateOrCreate(
            ['sku' => 'PRD-LT-001-LRG'],
            [
                'product_id' => $lemonTea->id,
                'price' => 16000, // Rp 16.000
                'is_active' => true,
                'is_sold_out' => false,
                'sort_order' => 2,
            ]
        );
        $ltLarge->translations()->updateOrCreate(['locale' => 'en'], ['name' => 'Large']);
        $ltLarge->translations()->updateOrCreate(['locale' => 'id'], ['name' => 'Besar']);

        // 4. Chicken Katsu (Has variants: Regular & Extra Rice)
        $chickenKatsu = Product::updateOrCreate(
            ['sku' => 'PRD-CK-001'],
            [
                'category_id' => $food?->id,
                'base_price' => 28000, // Rp 28.000 base
                'image_path' => 'products/chicken-katsu.jpg',
                'has_variants' => true,
                'is_active' => true,
                'is_sold_out' => false,
                'dine_in_available' => true,
                'takeaway_available' => true,
                'sort_order' => 4,
            ]
        );
        $chickenKatsu->translations()->updateOrCreate(
            ['locale' => 'en'],
            ['name' => 'Chicken Katsu', 'description' => 'Crispy breaded chicken cutlet with special savory sauce']
        );
        $chickenKatsu->translations()->updateOrCreate(
            ['locale' => 'id'],
            ['name' => 'Chicken Katsu', 'description' => 'Ayam katsu krispi gurih disajikan dengan saus katsu khas dan salad']
        );

        // Chicken Katsu Variants:
        $ckRegular = ProductVariant::updateOrCreate(
            ['sku' => 'PRD-CK-001-REG'],
            [
                'product_id' => $chickenKatsu->id,
                'price' => 28000, // Rp 28.000
                'is_active' => true,
                'is_sold_out' => false,
                'sort_order' => 1,
            ]
        );
        $ckRegular->translations()->updateOrCreate(['locale' => 'en'], ['name' => 'Regular']);
        $ckRegular->translations()->updateOrCreate(['locale' => 'id'], ['name' => 'Porsi Biasa']);

        $ckExtraRice = ProductVariant::updateOrCreate(
            ['sku' => 'PRD-CK-001-RIC'],
            [
                'product_id' => $chickenKatsu->id,
                'price' => 33000, // Rp 33.000
                'is_active' => true,
                'is_sold_out' => false,
                'sort_order' => 2,
            ]
        );
        $ckExtraRice->translations()->updateOrCreate(['locale' => 'en'], ['name' => 'Extra Rice']);
        $ckExtraRice->translations()->updateOrCreate(['locale' => 'id'], ['name' => 'Tambah Nasi']);
    }
}
