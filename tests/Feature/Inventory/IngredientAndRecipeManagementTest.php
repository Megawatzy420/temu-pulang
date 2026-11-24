<?php

namespace Tests\Feature\Inventory;

use App\Livewire\Inventory\IngredientManager;
use App\Livewire\Inventory\RecipeManager;
use App\Livewire\Menu\ModifierManager;
use App\Livewire\Menu\ProductForm;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientBalance;
use App\Models\IngredientCategory;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class IngredientAndRecipeManagementTest extends TestCase
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

    // =========================================================================
    // 1. Authorization & RBAC
    // =========================================================================

    public function test_owner_can_access_ingredient_manager(): void
    {
        $this->actingAs($this->owner)
            ->get(route('inventory.ingredients'))
            ->assertStatus(200)
            ->assertSee('Kelola Bahan Baku');
    }

    public function test_owner_can_access_recipe_manager(): void
    {
        $this->actingAs($this->owner)
            ->get(route('inventory.recipes'))
            ->assertStatus(200)
            ->assertSee('Resep Menu (BOM)');
    }

    public function test_cashier_cannot_access_ingredient_manager(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('inventory.ingredients'))
            ->assertRedirect(route('pos'));
    }

    public function test_cashier_cannot_access_recipe_manager(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('inventory.recipes'))
            ->assertRedirect(route('pos'));
    }

    public function test_guest_redirected_to_login(): void
    {
        $this->get(route('inventory.ingredients'))
            ->assertRedirect(route('login'));

        $this->get(route('inventory.recipes'))
            ->assertRedirect(route('login'));
    }

    // =========================================================================
    // 2. IngredientManager Component (CRUD & Balances)
    // =========================================================================

    public function test_owner_can_create_ingredient_category(): void
    {
        Livewire::actingAs($this->owner)
            ->test(IngredientManager::class)
            ->call('openCreateCategoryModal')
            ->set('categoryName', 'Dairy & Milk')
            ->set('categorySlug', 'dairy-milk')
            ->set('categorySortOrder', 1)
            ->call('saveCategory')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('ingredient_categories', [
            'name' => 'Dairy & Milk',
            'slug' => 'dairy-milk',
            'sort_order' => 1,
        ]);
    }

    public function test_owner_can_create_ingredient_with_initial_stock(): void
    {
        $cat = IngredientCategory::factory()->create(['name' => 'Coffee Beans']);

        Livewire::actingAs($this->owner)
            ->test(IngredientManager::class)
            ->call('openCreateIngredientModal')
            ->set('categoryId', $cat->id)
            ->set('sku', 'ING-ESP-01')
            ->set('name', 'Biji Kopi House Blend')
            ->set('unit', 'g')
            ->set('costPerUnit', 350)
            ->set('minStockAlert', '500.000')
            ->set('initialStock', '2500.000')
            ->call('saveIngredient')
            ->assertHasNoErrors();

        $ingredient = Ingredient::where('sku', 'ING-ESP-01')->first();
        $this->assertNotNull($ingredient);
        $this->assertEquals('Biji Kopi House Blend', $ingredient->name);
        $this->assertEquals(350, $ingredient->cost_per_unit);
        $this->assertEquals(500, (float) $ingredient->min_stock_alert);

        // Check balance created for branch_id = null
        $balance = IngredientBalance::where('ingredient_id', $ingredient->id)->whereNull('branch_id')->first();
        $this->assertNotNull($balance);
        $this->assertEquals(2500, (float) $balance->current_stock);

        // Check initial stock movement created
        $this->assertDatabaseHas('stock_movements', [
            'ingredient_id' => $ingredient->id,
            'movement_type' => StockMovement::TYPE_OPENING_STOCK,
            'quantity' => '2500.000',
            'balance_after' => '2500.000',
        ]);
    }

    public function test_owner_can_edit_ingredient(): void
    {
        $ingredient = Ingredient::factory()->create([
            'sku' => 'ING-MILK',
            'name' => 'Fresh Milk',
            'cost_per_unit' => 20,
            'min_stock_alert' => '1000.000',
        ]);

        Livewire::actingAs($this->owner)
            ->test(IngredientManager::class)
            ->call('openEditIngredientModal', $ingredient->id)
            ->set('name', 'Fresh Milk Pasteurisasi')
            ->set('costPerUnit', 22)
            ->set('minStockAlert', '1500.000')
            ->call('saveIngredient')
            ->assertHasNoErrors();

        $ingredient->refresh();
        $this->assertEquals('Fresh Milk Pasteurisasi', $ingredient->name);
        $this->assertEquals(22, $ingredient->cost_per_unit);
        $this->assertEquals(1500, (float) $ingredient->min_stock_alert);
    }

    public function test_owner_can_toggle_ingredient_active_status(): void
    {
        $ingredient = Ingredient::factory()->create(['is_active' => true]);

        Livewire::actingAs($this->owner)
            ->test(IngredientManager::class)
            ->call('toggleIngredientStatus', $ingredient->id);

        $this->assertFalse($ingredient->fresh()->is_active);

        Livewire::actingAs($this->owner)
            ->test(IngredientManager::class)
            ->call('toggleIngredientStatus', $ingredient->id);

        $this->assertTrue($ingredient->fresh()->is_active);
    }

    public function test_owner_can_soft_delete_ingredient(): void
    {
        $ingredient = Ingredient::factory()->create();

        Livewire::actingAs($this->owner)
            ->test(IngredientManager::class)
            ->call('confirmDeleteIngredient', $ingredient->id)
            ->call('deleteIngredient')
            ->assertHasNoErrors();

        $this->assertSoftDeleted('ingredients', ['id' => $ingredient->id]);
    }

    // =========================================================================
    // 3. ProductForm Recipe Management (Bill of Materials)
    // =========================================================================

    public function test_owner_can_configure_base_recipe_for_single_product(): void
    {
        $category = Category::first();
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'has_variants' => false,
            'base_price' => 20000,
        ]);

        $coffeeBeans = Ingredient::factory()->create(['name' => 'Coffee Beans', 'unit' => 'g', 'cost_per_unit' => 300]);
        $freshMilk = Ingredient::factory()->create(['name' => 'Fresh Milk', 'unit' => 'ml', 'cost_per_unit' => 25]);

        Livewire::actingAs($this->owner)
            ->test(ProductForm::class, ['id' => $product->id])
            ->set('nameId', 'Kopi Latte')
            ->set('basePrice', 20000)
            ->call('addBaseRecipeItem')
            ->set('baseRecipeItems.0.ingredient_id', $coffeeBeans->id)
            ->set('baseRecipeItems.0.quantity', '18.000')
            ->call('addBaseRecipeItem')
            ->set('baseRecipeItems.1.ingredient_id', $freshMilk->id)
            ->set('baseRecipeItems.1.quantity', '150.000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('recipe_items', [
            'product_id' => $product->id,
            'product_variant_id' => null,
            'ingredient_id' => $coffeeBeans->id,
            'quantity' => '18.000',
        ]);

        $this->assertDatabaseHas('recipe_items', [
            'product_id' => $product->id,
            'product_variant_id' => null,
            'ingredient_id' => $freshMilk->id,
            'quantity' => '150.000',
        ]);
    }

    public function test_owner_can_configure_variant_specific_recipe_overrides(): void
    {
        $category = Category::first();
        $product = Product::factory()->withTranslations(nameId: 'Kopi Susu')->create([
            'category_id' => $category->id,
            'has_variants' => true,
            'base_price' => 22000,
        ]);

        $variantRegular = ProductVariant::factory()->withTranslations(nameId: 'Regular')->create(['product_id' => $product->id, 'price' => 22000]);
        $variantLarge = ProductVariant::factory()->withTranslations(nameId: 'Large')->create(['product_id' => $product->id, 'price' => 28000]);

        $beans = Ingredient::factory()->create(['name' => 'Biji Kopi', 'unit' => 'g', 'cost_per_unit' => 300]);
        $milk = Ingredient::factory()->create(['name' => 'Susu', 'unit' => 'ml', 'cost_per_unit' => 20]);

        Livewire::actingAs($this->owner)
            ->test(ProductForm::class, ['id' => $product->id])
            // Set base recipe
            ->call('addBaseRecipeItem')
            ->set('baseRecipeItems.0.ingredient_id', $beans->id)
            ->set('baseRecipeItems.0.quantity', '18.000')
            // Set variant override for variant index 1 (Large)
            ->call('addVariantRecipeItem', 1)
            ->set('variantRecipeItems.1.0.ingredient_id', $milk->id)
            ->set('variantRecipeItems.1.0.quantity', '220.000')
            ->call('save')
            ->assertHasNoErrors();

        // Base recipe exists
        $this->assertDatabaseHas('recipe_items', [
            'product_id' => $product->id,
            'product_variant_id' => null,
            'ingredient_id' => $beans->id,
            'quantity' => '18.000',
        ]);

        // Variant override exists
        $this->assertDatabaseHas('recipe_items', [
            'product_id' => $product->id,
            'product_variant_id' => $variantLarge->id,
            'ingredient_id' => $milk->id,
            'quantity' => '220.000',
        ]);
    }

    // =========================================================================
    // 4. RecipeManager Component (Overview & COGS)
    // =========================================================================

    public function test_recipe_manager_displays_products_and_cogs(): void
    {
        $product = Product::factory()->create(['base_price' => 25000]);
        $beans = Ingredient::factory()->create(['cost_per_unit' => 300]); // 300 * 20 = 6,000

        RecipeItem::factory()->create([
            'product_id' => $product->id,
            'product_variant_id' => null,
            'ingredient_id' => $beans->id,
            'quantity' => '20.000',
        ]);

        Livewire::actingAs($this->owner)
            ->test(RecipeManager::class)
            ->assertSee($product->name)
            ->assertSee('Rp 6.000') // Calculated COGS
            ->assertSee('76%'); // Gross Margin: (25000 - 6000) / 25000 = 76%
    }

    // =========================================================================
    // 5. ModifierManager Recipe Rule Configuration
    // =========================================================================

    public function test_modifier_manager_saves_ingredient_recipe_rules(): void
    {
        $group = ModifierGroup::factory()->withTranslations(nameId: 'Pilihan Susu')->create(['slug' => 'pilihan-susu']);
        $oatMilk = Ingredient::factory()->create(['name' => 'Oat Milk', 'unit' => 'ml']);
        $regularMilk = Ingredient::factory()->create(['name' => 'Regular Milk', 'unit' => 'ml']);

        Livewire::actingAs($this->owner)
            ->test(ModifierManager::class)
            ->call('openEditModal', $group->id)
            ->set('options.0.nameId', 'Susu Gandum (Oat)')
            ->set('options.0.price', 6000)
            ->set('options.0.ingredientId', $oatMilk->id)
            ->set('options.0.recipeAction', 'replace')
            ->set('options.0.replacesIngredientId', $regularMilk->id)
            ->set('options.0.quantity', '150.000')
            ->call('save')
            ->assertHasNoErrors();

        $modifier = Modifier::where('modifier_group_id', $group->id)->first();
        $this->assertNotNull($modifier);

        $this->assertDatabaseHas('modifier_recipe_items', [
            'modifier_id' => $modifier->id,
            'ingredient_id' => $oatMilk->id,
            'action' => 'replace',
            'replaces_ingredient_id' => $regularMilk->id,
            'quantity' => '150.000',
        ]);
    }
}
