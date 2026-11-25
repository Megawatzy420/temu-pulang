<?php

namespace App\Services\Inventory;

use App\Models\Ingredient;
use App\Models\IngredientBalance;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\RefundTransaction;
use App\Models\StockMovement;
use App\Models\User;
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

    /**
     * Check whether an order has any order_deduction movements not yet credited back.
     */
    public function hasUncreditedDeductions(Order $order): bool
    {
        return $this->computeUncreditedByIngredient($order) !== [];
    }

    /**
     * Credit back deducted ingredients for a completed full refund.
     * Returns 'credited', 'nothing_to_return', or 'not_full_refund'.
     * Idempotent via RefundTransaction ULID on stock_movements.
     */
    public function restockForRefund(RefundTransaction $refund, bool $restock, User $actor): string
    {
        if (! $restock) {
            return 'nothing_to_return';
        }

        $order = $refund->order()->first();
        if (! $order || $order->payment_status !== Order::PAYMENT_STATUS_REFUNDED) {
            return 'not_full_refund';
        }

        if (StockMovement::where('idempotency_key', $refund->ulid)->exists()) {
            return 'credited';
        }

        return DB::transaction(function () use ($refund, $order, $actor): string {
            if (StockMovement::where('idempotency_key', $refund->ulid)->exists()) {
                return 'credited';
            }

            $toCredit = $this->computeUncreditedByIngredient($order);
            if ($toCredit === []) {
                return 'nothing_to_return';
            }

            $ingredientIds = array_keys($toCredit);
            sort($ingredientIds, SORT_NUMERIC);

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

            foreach ($ingredientIds as $ingredientId) {
                $balance = $lockedBalances->get($ingredientId);
                if (! $balance) {
                    continue;
                }

                $this->stockAdjustment->recordRefundCreditRow(
                    balance: $balance,
                    ingredientId: $ingredientId,
                    creditMilli: $toCredit[$ingredientId]['milli'],
                    unitCost: $toCredit[$ingredientId]['unit_cost'],
                    userId: $actor->id,
                    orderId: $order->id,
                    idempotencyKey: $refund->ulid,
                    notes: "Refund {$refund->refund_transaction_number}"
                );
            }

            return 'credited';
        });
    }

    /**
     * Compute uncredited deducted milli-units and unit_cost per ingredient for an order.
     *
     * @return array<int, array{milli: int, unit_cost: int}>
     */
    private function computeUncreditedByIngredient(Order $order): array
    {
        $deductions = StockMovement::where('reference_type', 'orders')
            ->where('reference_id', $order->id)
            ->where('movement_type', StockMovement::TYPE_ORDER_DEDUCTION)
            ->orderBy('id')
            ->get();

        if ($deductions->isEmpty()) {
            return [];
        }

        $deductedByIngredient = [];
        foreach ($deductions as $mov) {
            $id = (int) $mov->ingredient_id;
            $absMilli = abs($mov->quantity_milli);
            if (! isset($deductedByIngredient[$id])) {
                $deductedByIngredient[$id] = ['milli' => 0, 'unit_cost' => (int) $mov->unit_cost];
            }
            $deductedByIngredient[$id]['milli'] += $absMilli;
        }

        $credits = StockMovement::where('reference_type', 'orders')
            ->where('reference_id', $order->id)
            ->where('movement_type', StockMovement::TYPE_REFUND_CREDIT)
            ->get();

        foreach ($credits as $cred) {
            $id = (int) $cred->ingredient_id;
            if (isset($deductedByIngredient[$id])) {
                $deductedByIngredient[$id]['milli'] = max(0, $deductedByIngredient[$id]['milli'] - max(0, $cred->quantity_milli));
            }
        }

        return array_filter(
            $deductedByIngredient,
            static fn (array $entry): bool => $entry['milli'] > 0
        );
    }
}
