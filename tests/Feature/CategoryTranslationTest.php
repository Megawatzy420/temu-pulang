<?php

namespace Tests\Feature;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTranslationTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_can_be_created_with_slug_and_sort_order(): void
    {
        $category = Category::create([
            'slug' => 'coffee',
            'sort_order' => 5,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'slug' => 'coffee',
            'sort_order' => 5,
            'is_active' => true,
        ]);
    }

    public function test_category_translations_work_for_english_and_indonesian(): void
    {
        $category = Category::create([
            'slug' => 'beverages',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $category->translations()->create([
            'locale' => 'en',
            'name' => 'Beverages',
            'description' => 'Refreshing drinks',
        ]);

        $category->translations()->create([
            'locale' => 'id',
            'name' => 'Minuman',
            'description' => 'Aneka minuman segar',
        ]);

        app()->setLocale('id');
        $this->assertEquals('Minuman', $category->name);
        $this->assertEquals('Aneka minuman segar', $category->description);

        app()->setLocale('en');
        $this->assertEquals('Beverages', $category->name);
        $this->assertEquals('Refreshing drinks', $category->description);

        // Direct translation query
        $this->assertEquals('Minuman', $category->translate('id')->name);
        $this->assertEquals('Beverages', $category->translate('en')->name);
    }

    public function test_category_translation_falls_back_when_current_locale_is_missing(): void
    {
        config(['app.fallback_locale' => 'en']);

        $category = Category::create([
            'slug' => 'specialty',
            'sort_order' => 2,
        ]);

        // Only English translation exists
        $category->translations()->create([
            'locale' => 'en',
            'name' => 'Specialty Food',
            'description' => 'Chef recommendations',
        ]);

        // When requesting Japanese (not available), should fallback to 'en'
        app()->setLocale('ja');
        $this->assertEquals('Specialty Food', $category->name);
        $this->assertEquals('Chef recommendations', $category->description);
    }

    public function test_category_active_and_ordered_scopes(): void
    {
        $inactive = Category::create(['slug' => 'inactive-cat', 'sort_order' => 1, 'is_active' => false]);
        $second = Category::create(['slug' => 'second-cat', 'sort_order' => 20, 'is_active' => true]);
        $first = Category::create(['slug' => 'first-cat', 'sort_order' => 10, 'is_active' => true]);

        $activeCategories = Category::active()->get();
        $this->assertFalse($activeCategories->contains($inactive));
        $this->assertTrue($activeCategories->contains($first));
        $this->assertTrue($activeCategories->contains($second));

        $orderedCategories = Category::active()->ordered()->get();
        $this->assertEquals('first-cat', $orderedCategories->first()->slug);
        $this->assertEquals('second-cat', $orderedCategories->last()->slug);
    }

    public function test_category_soft_deletes(): void
    {
        $category = Category::create(['slug' => 'temporary', 'sort_order' => 99]);
        $category->delete();

        $this->assertSoftDeleted($category);
        $this->assertNull(Category::find($category->id));
        $this->assertNotNull(Category::withTrashed()->find($category->id));
    }
}
