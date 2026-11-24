<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductVariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_belongs_to_category(): void
    {
        $category = Category::create(['slug' => 'snacks', 'sort_order' => 1]);
        $product = Product::create([
            'category_id' => $category->id,
            'sku' => 'PRD-FF-001',
            'base_price' => 20000,
            'has_variants' => false,
        ]);

        $this->assertEquals($category->id, $product->category->id);
        $this->assertTrue($category->products->contains($product));
    }

    public function test_product_translations_work_for_english_and_indonesian(): void
    {
        $product = Product::create([
            'sku' => 'PRD-FF-001',
            'base_price' => 20000,
            'has_variants' => false,
        ]);

        $product->translations()->create([
            'locale' => 'en',
            'name' => 'French Fries',
            'description' => 'Crispy golden potato fries',
        ]);

        $product->translations()->create([
            'locale' => 'id',
            'name' => 'Kentang Goreng',
            'description' => 'Kentang goreng renyah gurih',
        ]);

        app()->setLocale('id');
        $this->assertEquals('Kentang Goreng', $product->name);
        $this->assertEquals('Kentang goreng renyah gurih', $product->description);

        app()->setLocale('en');
        $this->assertEquals('French Fries', $product->name);
        $this->assertEquals('Crispy golden potato fries', $product->description);
    }

    public function test_product_without_variants_uses_base_price(): void
    {
        $product = Product::create([
            'sku' => 'PRD-FF-001',
            'base_price' => 20000,
            'has_variants' => false,
        ]);

        $this->assertFalse($product->has_variants);
        $this->assertEquals(20000, $product->base_price);
        $this->assertEquals(20000, $product->effective_price);
        $this->assertCount(0, $product->variants);
    }

    public function test_product_with_variants_has_multiple_priced_variants(): void
    {
        $lemonTea = Product::create([
            'sku' => 'PRD-LT-001',
            'base_price' => 12000,
            'has_variants' => true,
        ]);

        $regular = ProductVariant::create([
            'product_id' => $lemonTea->id,
            'sku' => 'PRD-LT-001-REG',
            'price' => 12000, // Rp 12.000
            'sort_order' => 1,
        ]);
        $regular->translations()->create(['locale' => 'en', 'name' => 'Regular']);
        $regular->translations()->create(['locale' => 'id', 'name' => 'Biasa']);

        $large = ProductVariant::create([
            'product_id' => $lemonTea->id,
            'sku' => 'PRD-LT-001-LRG',
            'price' => 16000, // Rp 16.000
            'sort_order' => 2,
        ]);
        $large->translations()->create(['locale' => 'en', 'name' => 'Large']);
        $large->translations()->create(['locale' => 'id', 'name' => 'Besar']);

        $this->assertTrue($lemonTea->has_variants);
        $this->assertCount(2, $lemonTea->variants);
        $this->assertEquals(12000, $lemonTea->effective_price);

        app()->setLocale('id');
        $this->assertEquals('Biasa', $regular->name);
        $this->assertEquals('Besar', $large->name);

        app()->setLocale('en');
        $this->assertEquals('Regular', $regular->name);
        $this->assertEquals('Large', $large->name);
    }

    public function test_unique_sku_constraint_enforced_on_products(): void
    {
        Product::create([
            'sku' => 'PRD-UNIQUE-001',
            'base_price' => 10000,
        ]);

        $this->expectException(QueryException::class);

        Product::create([
            'sku' => 'PRD-UNIQUE-001',
            'base_price' => 15000,
        ]);
    }

    public function test_unique_sku_constraint_enforced_on_product_variants(): void
    {
        $product = Product::create([
            'sku' => 'PRD-PARENT-001',
            'base_price' => 10000,
            'has_variants' => true,
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'VAR-UNIQUE-001',
            'price' => 12000,
        ]);

        $this->expectException(QueryException::class);

        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'VAR-UNIQUE-001',
            'price' => 15000,
        ]);
    }

    public function test_dine_in_and_takeaway_availability_scopes(): void
    {
        $both = Product::create(['sku' => 'P-BOTH', 'base_price' => 10000, 'dine_in_available' => true, 'takeaway_available' => true]);
        $dineInOnly = Product::create(['sku' => 'P-DINE', 'base_price' => 10000, 'dine_in_available' => true, 'takeaway_available' => false]);
        $takeawayOnly = Product::create(['sku' => 'P-TAKE', 'base_price' => 10000, 'dine_in_available' => false, 'takeaway_available' => true]);

        $this->assertTrue(Product::dineIn()->get()->contains($both));
        $this->assertTrue(Product::dineIn()->get()->contains($dineInOnly));
        $this->assertFalse(Product::dineIn()->get()->contains($takeawayOnly));

        $this->assertTrue(Product::takeaway()->get()->contains($both));
        $this->assertFalse(Product::takeaway()->get()->contains($dineInOnly));
        $this->assertTrue(Product::takeaway()->get()->contains($takeawayOnly));
    }

    public function test_product_and_variant_soft_deletes(): void
    {
        $product = Product::create(['sku' => 'P-DEL', 'base_price' => 10000, 'has_variants' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-DEL', 'price' => 12000]);

        $variant->delete();
        $this->assertSoftDeleted($variant);

        $product->delete();
        $this->assertSoftDeleted($product);

        $this->assertNull(Product::find($product->id));
        $this->assertNotNull(Product::withTrashed()->find($product->id));
    }
}
