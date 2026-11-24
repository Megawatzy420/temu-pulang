<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\IngredientBalance;
use App\Models\IngredientCategory;
use App\Models\Modifier;
use App\Models\ModifierRecipeItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use App\Models\StockMovement;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class InventoryFoundationTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // 1. Schema: tables and columns exist
    // =========================================================================

    public function test_ingredient_categories_table_exists(): void
    {
        $cat = IngredientCategory::factory()->create([
            'name' => 'Dairy',
            'slug' => 'dairy',
            'sort_order' => 1,
        ]);

        $this->assertDatabaseHas('ingredient_categories', ['slug' => 'dairy', 'name' => 'Dairy']);
        $this->assertEquals(1, $cat->sort_order);
    }

    public function test_ingredients_table_exists_with_soft_deletes(): void
    {
        $ingredient = Ingredient::factory()->create(['name' => 'Full Cream Milk', 'unit' => 'ml']);

        $this->assertDatabaseHas('ingredients', ['name' => 'Full Cream Milk']);

        $ingredient->delete();

        $this->assertSoftDeleted('ingredients', ['id' => $ingredient->id]);
        $this->assertNull(Ingredient::find($ingredient->id));
        $this->assertNotNull(Ingredient::withTrashed()->find($ingredient->id));
    }

    public function test_ingredient_balances_table_exists(): void
    {
        $ingredient = Ingredient::factory()->create();
        $balance = IngredientBalance::factory()->create([
            'ingredient_id' => $ingredient->id,
            'current_stock' => '1500.000',
        ]);

        $this->assertDatabaseHas('ingredient_balances', [
            'ingredient_id' => $ingredient->id,
            'current_stock' => '1500.000',
        ]);
        $this->assertEquals('1500.000', $balance->current_stock);
    }

    public function test_recipe_items_table_exists(): void
    {
        $product = Product::factory()->create();
        $ingredient = Ingredient::factory()->create();
        $item = RecipeItem::factory()->create([
            'product_id' => $product->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => '250.000',
        ]);

        $this->assertDatabaseHas('recipe_items', ['quantity' => '250.000']);
        $this->assertEquals('250.000', $item->quantity);
    }

    public function test_modifier_recipe_items_table_exists(): void
    {
        $modifier = Modifier::factory()->create();
        $ingredient = Ingredient::factory()->create();
        $item = ModifierRecipeItem::factory()->create([
            'modifier_id' => $modifier->id,
            'ingredient_id' => $ingredient->id,
            'action' => ModifierRecipeItem::ACTION_ADD,
            'quantity' => '30.000',
        ]);

        $this->assertDatabaseHas('modifier_recipe_items', [
            'action' => 'add',
            'quantity' => '30.000',
        ]);
        $this->assertEquals('add', $item->action);
    }

    public function test_stock_movements_table_exists_without_updated_at(): void
    {
        $ingredient = Ingredient::factory()->create();
        $user = User::factory()->create();
        $movement = StockMovement::factory()->create([
            'ingredient_id' => $ingredient->id,
            'user_id' => $user->id,
            'movement_type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => '500.000',
            'balance_before' => '0.000',
            'balance_after' => '500.000',
        ]);

        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'stock_in']);
        $this->assertNull($movement->updated_at);
    }

    // =========================================================================
    // 2. Uniqueness constraints
    // =========================================================================

    public function test_uk_branch_ingredient_rejects_duplicate_null_branch(): void
    {
        $ingredient = Ingredient::factory()->create();

        IngredientBalance::create([
            'branch_id' => null,
            'ingredient_id' => $ingredient->id,
            'current_stock' => '0.000',
        ]);

        $this->expectException(QueryException::class);

        IngredientBalance::create([
            'branch_id' => null,
            'ingredient_id' => $ingredient->id,
            'current_stock' => '5.000',
        ]);
    }

    public function test_uk_branch_ingredient_allows_different_ingredients_on_same_branch(): void
    {
        $ing1 = Ingredient::factory()->create();
        $ing2 = Ingredient::factory()->create();

        IngredientBalance::create(['branch_id' => null, 'ingredient_id' => $ing1->id, 'current_stock' => '0.000']);
        IngredientBalance::create(['branch_id' => null, 'ingredient_id' => $ing2->id, 'current_stock' => '0.000']);

        $this->assertDatabaseCount('ingredient_balances', 2);
    }

    public function test_uk_branch_ingredient_allows_same_ingredient_on_different_branches(): void
    {
        $ingredient = Ingredient::factory()->create();

        IngredientBalance::create(['branch_id' => null, 'ingredient_id' => $ingredient->id, 'current_stock' => '0.000']);
        IngredientBalance::create(['branch_id' => 2,    'ingredient_id' => $ingredient->id, 'current_stock' => '0.000']);

        $this->assertDatabaseCount('ingredient_balances', 2);
    }

    public function test_uk_recipe_item_rejects_duplicate_null_variant(): void
    {
        $product = Product::factory()->create();
        $ingredient = Ingredient::factory()->create();

        RecipeItem::create([
            'product_id' => $product->id,
            'product_variant_id' => null,
            'ingredient_id' => $ingredient->id,
            'quantity' => '100.000',
        ]);

        $this->expectException(QueryException::class);

        RecipeItem::create([
            'product_id' => $product->id,
            'product_variant_id' => null,
            'ingredient_id' => $ingredient->id,
            'quantity' => '200.000',
        ]);
    }

    public function test_uk_recipe_item_allows_base_and_variant_specific_entries(): void
    {
        $product = Product::factory()->create(['has_variants' => true]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $ingredient = Ingredient::factory()->create();

        RecipeItem::create([
            'product_id' => $product->id,
            'product_variant_id' => null,
            'ingredient_id' => $ingredient->id,
            'quantity' => '100.000',
        ]);

        RecipeItem::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => '150.000',
        ]);

        $this->assertDatabaseCount('recipe_items', 2);
    }

    public function test_uk_stock_idempotency_rejects_same_key_and_ingredient(): void
    {
        $ingredient = Ingredient::factory()->create();
        $user = User::factory()->create();

        $base = [
            'ulid' => Str::ulid(),
            'ingredient_id' => $ingredient->id,
            'user_id' => $user->id,
            'movement_type' => StockMovement::TYPE_ORDER_DEDUCTION,
            'quantity' => '100.000',
            'balance_before' => '500.000',
            'balance_after' => '400.000',
            'unit_cost' => 0,
            'idempotency_key' => 'order-99-deduction',
        ];

        StockMovement::create($base);

        $this->expectException(QueryException::class);

        StockMovement::create(array_merge($base, ['ulid' => Str::ulid()]));
    }

    public function test_uk_stock_idempotency_allows_null_key_duplicates(): void
    {
        $ingredient = Ingredient::factory()->create();
        $user = User::factory()->create();

        $base = [
            'ingredient_id' => $ingredient->id,
            'user_id' => $user->id,
            'movement_type' => StockMovement::TYPE_ADJUSTMENT,
            'quantity' => '10.000',
            'balance_before' => '100.000',
            'balance_after' => '110.000',
            'unit_cost' => 0,
            'idempotency_key' => null,
        ];

        StockMovement::create(array_merge($base, ['ulid' => Str::ulid()]));
        StockMovement::create(array_merge($base, ['ulid' => Str::ulid()]));

        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_uk_stock_idempotency_allows_same_key_different_ingredient(): void
    {
        $ing1 = Ingredient::factory()->create();
        $ing2 = Ingredient::factory()->create();
        $user = User::factory()->create();

        $base = [
            'user_id' => $user->id,
            'movement_type' => StockMovement::TYPE_ORDER_DEDUCTION,
            'quantity' => '5.000',
            'balance_before' => '50.000',
            'balance_after' => '45.000',
            'unit_cost' => 0,
            'idempotency_key' => 'shared-key',
        ];

        StockMovement::create(array_merge($base, ['ulid' => Str::ulid(), 'ingredient_id' => $ing1->id]));
        StockMovement::create(array_merge($base, ['ulid' => Str::ulid(), 'ingredient_id' => $ing2->id]));

        $this->assertDatabaseCount('stock_movements', 2);
    }

    // =========================================================================
    // 3. StockMovement append-only guard
    // =========================================================================

    public function test_stock_movement_update_throws_domain_exception(): void
    {
        $movement = StockMovement::factory()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('immutable');

        $movement->update(['notes' => 'tampered']);
    }

    public function test_stock_movement_delete_throws_domain_exception(): void
    {
        $movement = StockMovement::factory()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('immutable');

        $movement->delete();
    }

    // =========================================================================
    // 4. Ingredient::toMilli() and fromMilli() precision
    // =========================================================================

    public function test_to_milli_converts_whole_number_string(): void
    {
        $this->assertEquals(2000, Ingredient::toMilli('2'));
        $this->assertEquals(0, Ingredient::toMilli('0'));
        $this->assertEquals(1000000, Ingredient::toMilli('1000'));
    }

    public function test_to_milli_converts_decimal_strings(): void
    {
        $this->assertEquals(1500, Ingredient::toMilli('1.500'));
        $this->assertEquals(1, Ingredient::toMilli('0.001'));
        $this->assertEquals(18500, Ingredient::toMilli('18.500'));
        $this->assertEquals(1250, Ingredient::toMilli('1.25'));   // 2-digit fraction
        $this->assertEquals(100, Ingredient::toMilli('0.1'));    // 1-digit fraction
    }

    public function test_to_milli_handles_integer_input(): void
    {
        $this->assertEquals(5000, Ingredient::toMilli(5));
    }

    public function test_from_milli_reconstructs_exact_decimal_string(): void
    {
        $this->assertEquals('1.500', Ingredient::fromMilli(1500));
        $this->assertEquals('0.001', Ingredient::fromMilli(1));
        $this->assertEquals('18.500', Ingredient::fromMilli(18500));
        $this->assertEquals('0.000', Ingredient::fromMilli(0));
        $this->assertEquals('1000.000', Ingredient::fromMilli(1000000));
    }

    public function test_round_trip_preserves_precision(): void
    {
        $values = ['18.500', '0.001', '0.000', '999.999', '1.100', '250.050'];

        foreach ($values as $v) {
            $this->assertEquals(
                $v,
                Ingredient::fromMilli(Ingredient::toMilli($v)),
                "Round-trip failed for: $v"
            );
        }
    }

    public function test_to_milli_rejects_malformed_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Ingredient::toMilli('1.2.3');
    }

    public function test_to_milli_rejects_too_many_decimal_places(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Ingredient::toMilli('1.1234');
    }

    // =========================================================================
    // 5. Relationships
    // =========================================================================

    public function test_ingredient_belongs_to_category(): void
    {
        $category = IngredientCategory::factory()->create();
        $ingredient = Ingredient::factory()->create(['category_id' => $category->id]);

        $loaded = Ingredient::with('category')->find($ingredient->id);
        $this->assertNotNull($loaded->category);
        $this->assertEquals($category->id, $loaded->category->id);
    }

    public function test_ingredient_has_many_balances(): void
    {
        $ingredient = Ingredient::factory()->create();
        IngredientBalance::factory()->create(['ingredient_id' => $ingredient->id]);
        IngredientBalance::factory()->create(['ingredient_id' => $ingredient->id, 'branch_id' => 2]);

        $this->assertCount(2, $ingredient->balances);
    }

    public function test_recipe_item_belongs_to_product_and_ingredient(): void
    {
        $product = Product::factory()->create();
        $ingredient = Ingredient::factory()->create();
        $item = RecipeItem::factory()->create([
            'product_id' => $product->id,
            'ingredient_id' => $ingredient->id,
        ]);

        $loaded = RecipeItem::with(['product', 'ingredient'])->find($item->id);
        $this->assertEquals($product->id, $loaded->product->id);
        $this->assertEquals($ingredient->id, $loaded->ingredient->id);
        $this->assertNull($loaded->variant);
    }

    public function test_recipe_item_belongs_to_variant(): void
    {
        $product = Product::factory()->create(['has_variants' => true]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $ingredient = Ingredient::factory()->create();
        $item = RecipeItem::factory()->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'ingredient_id' => $ingredient->id,
        ]);

        $loaded = RecipeItem::with('variant')->find($item->id);
        $this->assertNotNull($loaded->variant);
        $this->assertEquals($variant->id, $loaded->variant->id);
    }

    public function test_modifier_recipe_item_belongs_to_modifier_and_ingredient(): void
    {
        $modifier = Modifier::factory()->create();
        $ingredient = Ingredient::factory()->create();
        $item = ModifierRecipeItem::factory()->create([
            'modifier_id' => $modifier->id,
            'ingredient_id' => $ingredient->id,
        ]);

        $loaded = ModifierRecipeItem::with(['modifier', 'ingredient'])->find($item->id);
        $this->assertEquals($modifier->id, $loaded->modifier->id);
        $this->assertEquals($ingredient->id, $loaded->ingredient->id);
    }

    public function test_modifier_recipe_item_replace_action_with_replaces_ingredient(): void
    {
        $modifier = Modifier::factory()->create();
        $ingredient = Ingredient::factory()->create(['name' => 'Oat Milk']);
        $replaces = Ingredient::factory()->create(['name' => 'Regular Milk']);
        $item = ModifierRecipeItem::factory()->replace($replaces->id)->create([
            'modifier_id' => $modifier->id,
            'ingredient_id' => $ingredient->id,
        ]);

        $loaded = ModifierRecipeItem::with('replacesIngredient')->find($item->id);
        $this->assertEquals(ModifierRecipeItem::ACTION_REPLACE, $loaded->action);
        $this->assertNotNull($loaded->replacesIngredient);
        $this->assertEquals($replaces->id, $loaded->replacesIngredient->id);
    }

    public function test_product_has_many_recipe_items(): void
    {
        $product = Product::factory()->create();
        RecipeItem::factory()->count(3)->create(['product_id' => $product->id]);

        $this->assertCount(3, $product->recipeItems);
    }

    public function test_product_variant_has_many_recipe_items(): void
    {
        $product = Product::factory()->create(['has_variants' => true]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        RecipeItem::factory()->count(2)->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
        ]);

        $this->assertCount(2, $variant->recipeItems);
    }

    public function test_modifier_has_many_modifier_recipe_items(): void
    {
        $modifier = Modifier::factory()->create();
        ModifierRecipeItem::factory()->count(2)->create(['modifier_id' => $modifier->id]);

        $this->assertCount(2, $modifier->modifierRecipeItems);
    }

    // =========================================================================
    // 6. IngredientBalance::isLowStock()
    // =========================================================================

    public function test_is_low_stock_returns_true_when_at_or_below_min(): void
    {
        $ingredient = Ingredient::factory()->create(['min_stock_alert' => '100.000']);
        $balance = IngredientBalance::factory()->create([
            'ingredient_id' => $ingredient->id,
            'current_stock' => '99.000',
        ]);

        $balance->load('ingredient');
        $this->assertTrue($balance->isLowStock());
    }

    public function test_is_low_stock_returns_true_when_exactly_at_min(): void
    {
        $ingredient = Ingredient::factory()->create(['min_stock_alert' => '100.000']);
        $balance = IngredientBalance::factory()->create([
            'ingredient_id' => $ingredient->id,
            'current_stock' => '100.000',
        ]);

        $balance->load('ingredient');
        $this->assertTrue($balance->isLowStock());
    }

    public function test_is_low_stock_returns_false_when_above_min(): void
    {
        $ingredient = Ingredient::factory()->create(['min_stock_alert' => '100.000']);
        $balance = IngredientBalance::factory()->create([
            'ingredient_id' => $ingredient->id,
            'current_stock' => '100.001',
        ]);

        $balance->load('ingredient');
        $this->assertFalse($balance->isLowStock());
    }

    // =========================================================================
    // 7. Milli-unit accessors on models
    // =========================================================================

    public function test_ingredient_balance_current_stock_milli_accessor(): void
    {
        $ingredient = Ingredient::factory()->create();
        $balance = IngredientBalance::factory()->create([
            'ingredient_id' => $ingredient->id,
            'current_stock' => '1.500',
        ]);

        $this->assertEquals(1500, $balance->current_stock_milli);
    }

    public function test_recipe_item_quantity_milli_accessor(): void
    {
        $item = RecipeItem::factory()->create(['quantity' => '250.000']);
        $this->assertEquals(250000, $item->quantity_milli);
    }

    public function test_stock_movement_quantity_milli_accessor(): void
    {
        $movement = StockMovement::factory()->create([
            'quantity' => '0.750',
            'balance_before' => '10.000',
            'balance_after' => '10.750',
        ]);

        $this->assertEquals(750, $movement->quantity_milli);
        $this->assertEquals(10000, $movement->balance_before_milli);
        $this->assertEquals(10750, $movement->balance_after_milli);
    }
}
