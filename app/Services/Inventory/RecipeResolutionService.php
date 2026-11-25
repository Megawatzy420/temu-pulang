<?php

namespace App\Services\Inventory;

use App\Models\ModifierRecipeItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use Illuminate\Support\Facades\Log;

class RecipeResolutionService
{
    /**
     * Resolve total required ingredient quantities (in integer milli-units)
     * across all items in a paid order, sorted ascending by ingredient_id.
     *
     * @return array<int, int> [ingredient_id => total_milli_units]
     */
    public function resolveForOrder(Order $order): array
    {
        $order->loadMissing(['items.modifiers']);

        $totals = [];

        foreach ($order->items as $item) {
            $itemIngredients = $this->resolveForOrderItem($item, $order->id);

            foreach ($itemIngredients as $ingredientId => $milli) {
                $totals[$ingredientId] = ($totals[$ingredientId] ?? 0) + $milli;
            }
        }

        $totals = array_filter($totals, static fn (int $milli): bool => $milli > 0);
        ksort($totals, SORT_NUMERIC);

        return $totals;
    }

    /**
     * Resolve total required ingredient quantities (in integer milli-units)
     * for a single order item (already multiplied by item quantity).
     *
     * @return array<int, int> [ingredient_id => total_milli_units]
     */
    public function resolveForOrderItem(OrderItem $item, ?int $orderId = null): array
    {
        if ($item->product_id === null || ! Product::whereKey($item->product_id)->exists()) {
            Log::warning('Skipping order item deduction: product no longer exists.', [
                'order_id' => $orderId ?? $item->order_id,
                'order_item_id' => $item->id,
                'product_id' => $item->product_id,
            ]);

            return [];
        }

        if ($item->product_variant_id !== null && ! ProductVariant::whereKey($item->product_variant_id)->exists()) {
            Log::warning('Skipping order item deduction: product variant no longer exists.', [
                'order_id' => $orderId ?? $item->order_id,
                'order_item_id' => $item->id,
                'product_variant_id' => $item->product_variant_id,
            ]);

            return [];
        }

        $perUnit = [];

        // 1. Base recipe items (product_variant_id IS NULL)
        $baseItems = RecipeItem::where('product_id', $item->product_id)
            ->whereNull('product_variant_id')
            ->get();

        foreach ($baseItems as $recipeItem) {
            $perUnit[$recipeItem->ingredient_id] = $recipeItem->quantity_milli;
        }

        // 2. Variant overrides replace base quantity for the same ingredient;
        //    base-only ingredients remain, variant-only ingredients are added.
        if ($item->product_variant_id !== null) {
            $variantItems = RecipeItem::where('product_id', $item->product_id)
                ->where('product_variant_id', $item->product_variant_id)
                ->get();

            foreach ($variantItems as $variantItem) {
                $perUnit[$variantItem->ingredient_id] = $variantItem->quantity_milli;
            }
        }

        // 3. Modifier recipe deltas (add / replace)
        $item->loadMissing('modifiers');
        $modifierIds = $item->modifiers
            ->pluck('modifier_id')
            ->filter(static fn (mixed $id): bool => $id !== null)
            ->values()
            ->all();

        if ($modifierIds !== []) {
            $modifierRules = ModifierRecipeItem::whereIn('modifier_id', $modifierIds)->get();

            foreach ($modifierRules as $rule) {
                if ($rule->action === ModifierRecipeItem::ACTION_REPLACE) {
                    if ($rule->replaces_ingredient_id !== null) {
                        $perUnit[$rule->replaces_ingredient_id] = 0;
                    }
                    $perUnit[$rule->ingredient_id] = ($perUnit[$rule->ingredient_id] ?? 0) + $rule->quantity_milli;
                } else {
                    $perUnit[$rule->ingredient_id] = ($perUnit[$rule->ingredient_id] ?? 0) + $rule->quantity_milli;
                }
            }
        }

        // 4. Multiply by item quantity and clamp at 0
        $itemQuantity = max(0, (int) $item->quantity);
        $resolved = [];

        foreach ($perUnit as $ingredientId => $unitMilli) {
            if ($unitMilli > 0 && $itemQuantity > 0) {
                $resolved[$ingredientId] = $unitMilli * $itemQuantity;
            }
        }

        return $resolved;
    }
}
