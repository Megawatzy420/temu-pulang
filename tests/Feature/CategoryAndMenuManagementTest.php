<?php

namespace Tests\Feature;

use App\Livewire\Menu\CategoryManager;
use App\Livewire\Menu\ModifierManager;
use App\Livewire\Menu\ProductForm;
use App\Livewire\Menu\ProductIndex;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CategoryAndMenuManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->owner = User::where('email', 'owner@temupulang.local')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();
    }

    public function test_owner_can_access_category_manager(): void
    {
        $this->actingAs($this->owner)
            ->get(route('menu.categories'))
            ->assertStatus(200)
            ->assertSee('Kategori Menu');
    }

    public function test_cashier_cannot_access_category_manager(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('menu.categories'))
            ->assertRedirect(route('pos'));
    }

    public function test_owner_can_create_and_edit_category(): void
    {
        Livewire::actingAs($this->owner)
            ->test(CategoryManager::class)
            ->call('openCreateModal')
            ->set('nameId', 'Mocktails Eksotis')
            ->set('nameEn', 'Exotic Mocktails')
            ->set('descriptionId', 'Minuman segar buah')
            ->set('sortOrder', 5)
            ->call('save')
            ->assertHasNoErrors();

        $category = Category::where('slug', 'mocktails-eksotis')->first();
        $this->assertNotNull($category);
        $this->assertEquals('Mocktails Eksotis', $category->getTranslatedAttribute('name', 'id'));
        $this->assertEquals('Exotic Mocktails', $category->getTranslatedAttribute('name', 'en'));

        // Test Edit
        Livewire::actingAs($this->owner)
            ->test(CategoryManager::class)
            ->call('openEditModal', $category->id)
            ->set('nameId', 'Mocktails Tropis')
            ->call('save');

        $this->assertEquals('Mocktails Tropis', $category->fresh()->getTranslatedAttribute('name', 'id'));
    }

    public function test_owner_can_create_modifier_group_with_options(): void
    {
        Livewire::actingAs($this->owner)
            ->test(ModifierManager::class)
            ->call('openCreateModal')
            ->set('nameId', 'Pilihan Sirup')
            ->set('nameEn', 'Syrup Options')
            ->set('minSelections', 0)
            ->set('maxSelections', 2)
            ->set('isRequired', false)
            ->set('options', [
                [
                    'id' => null,
                    'nameId' => 'Sirup Karamel',
                    'nameEn' => 'Caramel Syrup',
                    'price' => 4000,
                    'isActive' => true,
                    'isSoldOut' => false,
                ],
                [
                    'id' => null,
                    'nameId' => 'Sirup Hazelnut',
                    'nameEn' => 'Hazelnut Syrup',
                    'price' => 4000,
                    'isActive' => true,
                    'isSoldOut' => false,
                ],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $group = ModifierGroup::with(['modifiers.translations', 'translations'])->where('slug', 'pilihan-sirup')->first();
        $this->assertNotNull($group);
        $this->assertCount(2, $group->modifiers);
        $this->assertEquals(4000, $group->modifiers->first()->price);
        $this->assertEquals('Sirup Karamel', $group->modifiers->first()->getTranslatedAttribute('name', 'id'));
    }

    public function test_owner_can_create_product_with_variants_and_modifiers(): void
    {
        $category = Category::first();
        $modGroup = ModifierGroup::first();

        Livewire::actingAs($this->owner)
            ->test(ProductForm::class)
            ->set('categoryId', $category->id)
            ->set('nameId', 'Kopi Spesial Temu')
            ->set('nameEn', 'Temu Special Blend')
            ->set('descriptionId', 'Kopi racikan khas')
            ->set('sku', 'COF-SPEC-01')
            ->set('basePrice', 25000)
            ->set('hasVariants', true)
            ->set('variants', [
                [
                    'id' => null,
                    'sku' => 'COF-SPEC-REG',
                    'name_id' => 'Ukuran Sedang',
                    'name_en' => 'Regular Size',
                    'price' => 25000,
                    'is_active' => true,
                    'is_sold_out' => false,
                    'sort_order' => 0,
                ],
                [
                    'id' => null,
                    'sku' => 'COF-SPEC-LRG',
                    'name_id' => 'Ukuran Besar',
                    'name_en' => 'Large Size',
                    'price' => 30000,
                    'is_active' => true,
                    'is_sold_out' => false,
                    'sort_order' => 1,
                ],
            ])
            ->set('selectedModifierGroupIds', [$modGroup->id])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('menu.products'));

        $product = Product::with(['translations', 'variants.translations', 'modifierGroups'])->where('sku', 'COF-SPEC-01')->first();
        $this->assertNotNull($product);
        $this->assertEquals(25000, $product->base_price);
        $this->assertTrue($product->has_variants);
        $this->assertCount(2, $product->variants);
        $this->assertCount(1, $product->modifierGroups);
        $this->assertEquals('Kopi Spesial Temu', $product->getTranslatedAttribute('name', 'id'));
        $this->assertEquals('Temu Special Blend', $product->getTranslatedAttribute('name', 'en'));
    }

    public function test_owner_can_toggle_sold_out_state(): void
    {
        $product = Product::first();
        $this->assertFalse((bool) $product->is_sold_out);

        Livewire::actingAs($this->owner)
            ->test(ProductIndex::class)
            ->call('toggleSoldOut', $product->id);

        $this->assertTrue((bool) $product->fresh()->is_sold_out);
    }
}
