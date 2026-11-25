<?php

namespace App\Services\Inventory;

use App\Models\Ingredient;
use App\Models\IngredientBalance;
use App\Models\PaymentTransaction;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function __construct(
        protected RecipeResolutionService $recipeResolution,
        protected StockAdjustmentService $stockAdjustment
    ) {}

    /**
     * Deduct ingredient inventory for a settled PaymentTransaction.
     * Idempotent via PaymentTransaction ULID on stock_movements.
     * Locks ingredient_balances in ascending ingredient_id order to prevent deadlocks.
     */
    public function deductForPayment(PaymentTransaction $transaction): void
    {
        if (StockMovement::where('idempotency_key', $transaction->ulid)->exists()) {
            return;
        }

        $order = $transaction->order()->with(['items.modifiers'])->first();
        if (! $order) {
            return;
        }

        $requiredMilli = $this->recipeResolution->resolveForOrder($order);
        if ($requiredMilli === []) {
            return;
        }

        $ingredientIds = array_keys($requiredMilli);
        sort($ingredientIds, SORT_NUMERIC);

        DB::transaction(function () use ($transaction, $order, $requiredMilli, $ingredientIds): void {
            // Re-check idempotency inside transaction
            if (StockMovement::where('idempotency_key', $transaction->ulid)->exists()) {
                return;
            }

            // Ensure all single-store balance rows exist before locking
            $balanceIds = [];
            foreach ($ingredientIds as $ingredientId) {
                $balance = IngredientBalance::firstOrCreate(
                    ['branch_id' => null, 'ingredient_id' => $ingredientId],
                    ['current_stock' => '0.000']
                );
                $balanceIds[] = $balance->id;
            }

            // Lock balances in ascending ingredient_id order (deadlock-safe)
            $lockedBalances = IngredientBalance::whereIn('id', $balanceIds)
                ->orderBy('ingredient_id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('ingredient_id');

            $ingredients = Ingredient::whereIn('id', $ingredientIds)
                ->get()
                ->keyBy('id');

            foreach ($ingredientIds as $ingredientId) {
                $balance = $lockedBalances->get($ingredientId);
                $ingredient = $ingredients->get($ingredientId);

                if (! $balance || ! $ingredient) {
                    continue;
                }

                $this->stockAdjustment->recordOrderDeductionRow(
                    balance: $balance,
                    ingredient: $ingredient,
                    deductMilli: $requiredMilli[$ingredientId],
                    userId: (int) $transaction->user_id,
                    orderId: (int) $order->id,
                    idempotencyKey: $transaction->ulid,
                    notes: "Order {$order->order_number} ({$transaction->payment_transaction_number})"
                );
            }
        });
    }
}
