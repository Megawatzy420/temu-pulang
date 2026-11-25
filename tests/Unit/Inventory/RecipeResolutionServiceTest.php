<?php

namespace Tests\Unit\Inventory;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\ModifierRecipeItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use App\Models\Shift;
use App\Models\User;
use App\Services\Inventory\RecipeResolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecipeResolutionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected RecipeResolutionService $service;

    protected Ingredient $espresso;

    protected Ingredient $milk;

    protected Ingredient $oatMilk;

    protected Ingredient $syrup;

    protected Product $product;

    protected ProductVariant $largeVariant;

    protected Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->service = app(RecipeResolutionService::class);

        $ingCat = IngredientCategory::create([
            'name' => 'Ingredients',
            'slug' => 'ingredients-unit',
            'sort_order' => 1,
        ]);

        $this->espresso = Ingredient::create([
            'category_id' => $ingCat->id,
            'sku' => 'ING-ESP',
            'name' => 'Espresso Beans',
            'unit' => 'kg',
            'cost_per_unit' => 200000,
            'min_stock_alert' => '1.000',
        ]);

        $this->milk = Ingredient::create([
            'category_id' => $ingCat->id,
            'sku' => 'ING-MILK',
            'name' => 'Fresh Milk',
            'unit' => 'L',
            'cost_per_unit' => 25000,
            'min_stock_alert' => '1.000',
        ]);

        $this->oatMilk = Ingredient::create([
            'category_id' => $ingCat->id,
            'sku' => 'ING-OAT',
            'name' => 'Oat Milk',
            'unit' => 'L',
            'cost_per_unit' => 45000,
            'min_stock_alert' => '1.000',
        ]);

        $this->syrup = Ingredient::create([
            'category_id' => $ingCat->id,
            'sku' => 'ING-SYR',
            'name' => 'Caramel Syrup',
            'unit' => 'L',
            'cost_per_unit' => 90000,
            'min_stock_alert' => '0.500',
        ]);

        $cat = Category::firstOrFail();
        $this->product = Product::create([
            'category_id' => $cat->id,
            'sku' => 'PROD-LATTE',
            'base_price' => 30000,
            'has_variants' => true,
            'is_active' => true,
        ]);

        $this->largeVariant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'PROD-LATTE-L',
            'price' => 36000,
            'is_active' => true,
        ]);

        // Base recipe: 0.025 espresso + 0.150 milk
        RecipeItem::create([
            'product_id' => $this->product->id,
            'product_variant_id' => null,
            'ingredient_id' => $this->espresso->id,
            'quantity' => '0.025',
        ]);
        RecipeItem::create([
            'product_id' => $this->product->id,
            'product_variant_id' => null,
            'ingredient_id' => $this->milk->id,
            'quantity' => '0.150',
        ]);

        // Variant override: large replaces milk with 0.250 (espresso stays 0.025, syrup added 0.020)
        RecipeItem::create([
            'product_id' => $this->product->id,
            'product_variant_id' => $this->largeVariant->id,
            'ingredient_id' => $this->milk->id,
            'quantity' => '0.250',
        ]);
        RecipeItem::create([
            'product_id' => $this->product->id,
            'product_variant_id' => $this->largeVariant->id,
            'ingredient_id' => $this->syrup->id,
            'quantity' => '0.020',
        ]);

        $cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();
        $shift = Shift::create([
            'shift_number' => 'SHF-UNIT-001',
            'user_id' => $cashier->id,
            'status' => 'open',
            'opening_cash' => 100000,
            'opened_at' => now('Asia/Jakarta'),
        ]);

        $this->order = Order::create([
            'ulid' => (string) Str::ulid(),
            'order_number' => 'ORD-UNIT-001',
            'daily_sequence' => 1,
            'daily_order_number' => 'TP-001',
            'call_number' => 'TP-001',
            'business_date' => now('Asia/Jakarta')->toDateString(),
            'order_type' => Order::TYPE_DINE_IN,
            'status' => Order::STATUS_PAID,
            'payment_status' => Order::PAYMENT_STATUS_PAID,
            'subtotal' => 30000,
            'tax_amount' => 0,
            'service_charge_amount' => 0,
            'total_amount' => 30000,
            'user_id' => $cashier->id,
            'shift_id' => $shift->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    public function test_resolves_base_recipe_with_exact_decimal_item_quantity_multiplication(): void
    {
        OrderItem::create([
            'order_id' => $this->order->id,
            'product_id' => $this->product->id,
            'product_variant_id' => null,
            'sku' => $this->product->sku,
            'product_name_id' => 'Latte',
            'product_name_en' => 'Latte',
            'unit_price' => 30000,
            'modifiers_unit_price' => 0,
            'final_unit_price' => 30000,
            'quantity' => 7,
            'subtotal' => 210000,
        ]);

        $resolved = $this->service->resolveForOrder($this->order);

        // 0.025 * 7 = 0.175 (175 milli), 0.150 * 7 = 1.050 (1050 milli)
        $this->assertSame([
            $this->espresso->id => 175,
            $this->milk->id => 1050,
        ], $resolved);
    }

    public function test_variant_override_replaces_matching_ingredient_and_keeps_base_only_ingredients(): void
    {
        OrderItem::create([
            'order_id' => $this->order->id,
            'product_id' => $this->product->id,
            'product_variant_id' => $this->largeVariant->id,
            'sku' => $this->largeVariant->sku,
            'product_name_id' => 'Latte',
            'product_name_en' => 'Latte',
            'variant_name_id' => 'Large',
            'variant_name_en' => 'Large',
            'unit_price' => 36000,
            'modifiers_unit_price' => 0,
            'final_unit_price' => 36000,
            'quantity' => 1,
            'subtotal' => 36000,
        ]);

        $resolved = $this->service->resolveForOrder($this->order);

        $this->assertSame([
            $this->espresso->id => 25,
            $this->milk->id => 250,
            $this->syrup->id => 20,
        ], $resolved);
    }

    public function test_modifier_add_and_replace_and_never_below_zero(): void
    {
        $group = ModifierGroup::create(['slug' => 'mod-unit-grp', 'is_required' => false, 'min_select' => 0, 'max_select' => 5, 'is_active' => true]);
        $extraShot = Modifier::create(['modifier_group_id' => $group->id, 'price' => 5000, 'is_active' => true]);
        $oatSwap = Modifier::create(['modifier_group_id' => $group->id, 'price' => 8000, 'is_active' => true]);
        $ghostSwap = Modifier::create(['modifier_group_id' => $group->id, 'price' => 0, 'is_active' => true]);

        // Add 0.025 espresso
        ModifierRecipeItem::create([
            'modifier_id' => $extraShot->id,
            'ingredient_id' => $this->espresso->id,
            'action' => ModifierRecipeItem::ACTION_ADD,
            'quantity' => '0.025',
        ]);

        // Replace milk with 0.200 oatMilk
        ModifierRecipeItem::create([
            'modifier_id' => $oatSwap->id,
            'ingredient_id' => $this->oatMilk->id,
            'action' => ModifierRecipeItem::ACTION_REPLACE,
            'replaces_ingredient_id' => $this->milk->id,
            'quantity' => '0.200',
        ]);

        // Replace syrup (not in base recipe!) -> clamped to 0, adds 0.100 espresso
        ModifierRecipeItem::create([
            'modifier_id' => $ghostSwap->id,
            'ingredient_id' => $this->espresso->id,
            'action' => ModifierRecipeItem::ACTION_REPLACE,
            'replaces_ingredient_id' => $this->syrup->id,
            'quantity' => '0.100',
        ]);

        $item = OrderItem::create([
            'order_id' => $this->order->id,
            'product_id' => $this->product->id,
            'product_variant_id' => null,
            'sku' => $this->product->sku,
            'product_name_id' => 'Latte',
            'product_name_en' => 'Latte',
            'unit_price' => 30000,
            'modifiers_unit_price' => 13000,
            'final_unit_price' => 43000,
            'quantity' => 3,
            'subtotal' => 129000,
        ]);

        foreach ([$extraShot, $oatSwap, $ghostSwap] as $mod) {
            OrderItemModifier::create([
                'order_item_id' => $item->id,
                'modifier_id' => $mod->id,
                'modifier_group_id' => $group->id,
                'group_name_id' => 'Add-ons',
                'group_name_en' => 'Add-ons',
                'modifier_name_id' => 'Mod',
                'modifier_name_en' => 'Mod',
                'unit_price' => $mod->price,
            ]);
        }

        $resolved = $this->service->resolveForOrder($this->order);

        // Espresso per unit: 0.025 + 0.025 + 0.100 = 0.150 * 3 = 0.450 (450)
        // Milk removed (0), OatMilk: 0.200 * 3 = 0.600 (600)
        $this->assertSame([
            $this->espresso->id => 450,
            $this->oatMilk->id => 600,
        ], $resolved);
    }

    public function test_skips_deleted_product_or_product_without_recipe_gracefully(): void
    {
        $noRecipeProduct = Product::create([
            'category_id' => $this->product->category_id,
            'sku' => 'PROD-NO-RECIPE',
            'base_price' => 15000,
            'has_variants' => false,
            'is_active' => true,
        ]);

        OrderItem::create([
            'order_id' => $this->order->id,
            'product_id' => $noRecipeProduct->id,
            'product_variant_id' => null,
            'sku' => $noRecipeProduct->sku,
            'product_name_id' => 'Water',
            'product_name_en' => 'Water',
            'unit_price' => 15000,
            'modifiers_unit_price' => 0,
            'final_unit_price' => 15000,
            'quantity' => 2,
            'subtotal' => 30000,
        ]);

        // Deleted product (product_id = null)
        OrderItem::create([
            'order_id' => $this->order->id,
            'product_id' => null,
            'product_variant_id' => null,
            'sku' => 'DELETED',
            'product_name_id' => 'Deleted Item',
            'product_name_en' => 'Deleted Item',
            'unit_price' => 10000,
            'modifiers_unit_price' => 0,
            'final_unit_price' => 10000,
            'quantity' => 1,
            'subtotal' => 10000,
        ]);

        $this->assertSame([], $this->service->resolveForOrder($this->order));
    }
}
