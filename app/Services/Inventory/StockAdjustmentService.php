<?php

namespace App\Services\Inventory;

use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Inventory\InvalidStockQuantityException;
use App\Exceptions\Inventory\StockReasonRequiredException;
use App\Models\Ingredient;
use App\Models\IngredientBalance;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockAdjustmentService
{
    /**
     * Parse a decimal quantity string (or integer) into exact integer milli-units.
     * Accepts "." or "," as the single decimal separator (up to 3 decimal places).
     * Rejects thousand separators (e.g. "1.000.000"), letters, or malformed strings.
     *
     * @throws InvalidStockQuantityException
     */
    public static function parseQuantityToMilli(string|int $raw, bool $allowNegative = false): int
    {
        $str = trim((string) $raw);

        if ($str === '') {
            throw new InvalidStockQuantityException('Quantity cannot be empty.');
        }

        // Reject mixed separators (e.g. "1,000.500")
        if (strpos($str, ',') !== false && strpos($str, '.') !== false) {
            throw new InvalidStockQuantityException("Mixed decimal separators in '{$raw}'.");
        }

        $normalized = str_replace(',', '.', $str);

        $pattern = $allowNegative
            ? '/^-?\d{1,9}(\.\d{1,3})?$/'
            : '/^\d{1,9}(\.\d{1,3})?$/';

        if (! preg_match($pattern, $normalized)) {
            throw new InvalidStockQuantityException("Invalid decimal quantity: '{$raw}'.");
        }

        return Ingredient::toMilli($normalized);
    }

    /**
     * Record incoming stock for an ingredient.
     *
     * @throws InvalidStockQuantityException
     */
    public function stockIn(
        Ingredient $ingredient,
        User $user,
        string|int $quantity,
        ?int $unitCost = null,
        ?string $notes = null,
        ?string $idempotencyKey = null
    ): StockMovement {
        $deltaMilli = self::parseQuantityToMilli($quantity, allowNegative: false);

        if ($deltaMilli <= 0) {
            throw new InvalidStockQuantityException('Stock-in quantity must be greater than zero.');
        }

        $resolvedUnitCost = $unitCost ?? (int) $ingredient->cost_per_unit;
        if ($resolvedUnitCost < 0) {
            throw new InvalidStockQuantityException('Unit cost cannot be negative.');
        }

        $cleanNotes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;
        $cleanKey = $idempotencyKey !== null && trim($idempotencyKey) !== '' ? trim($idempotencyKey) : null;

        return $this->applySignedMutation(
            ingredient: $ingredient,
            user: $user,
            movementType: StockMovement::TYPE_STOCK_IN,
            deltaMilli: $deltaMilli,
            unitCost: $resolvedUnitCost,
            notes: $cleanNotes,
            idempotencyKey: $cleanKey,
        );
    }

    /**
     * Record wastage/spoilage (always stored as a negative movement).
     *
     * @throws StockReasonRequiredException|InvalidStockQuantityException|InsufficientStockException
     */
    public function recordWastage(
        Ingredient $ingredient,
        User $user,
        string|int $quantity,
        string $notes,
        ?string $idempotencyKey = null
    ): StockMovement {
        $cleanNotes = trim($notes);
        if ($cleanNotes === '') {
            throw new StockReasonRequiredException('Wastage reason note is required.');
        }

        $qtyMilli = self::parseQuantityToMilli($quantity, allowNegative: false);
        if ($qtyMilli <= 0) {
            throw new InvalidStockQuantityException('Wastage quantity must be greater than zero.');
        }

        $cleanKey = $idempotencyKey !== null && trim($idempotencyKey) !== '' ? trim($idempotencyKey) : null;

        return $this->applySignedMutation(
            ingredient: $ingredient,
            user: $user,
            movementType: StockMovement::TYPE_WASTAGE,
            deltaMilli: -$qtyMilli,
            unitCost: (int) $ingredient->cost_per_unit,
            notes: $cleanNotes,
            idempotencyKey: $cleanKey,
        );
    }

    /**
     * Adjust stock in either "counted" mode (target physical stock) or "difference" mode (signed delta).
     * Returns null when the resulting difference is zero (no movement recorded).
     *
     * @throws StockReasonRequiredException|InvalidStockQuantityException|InsufficientStockException
     */
    public function adjust(
        Ingredient $ingredient,
        User $user,
        string|int $quantity,
        string $mode,
        string $notes,
        ?string $idempotencyKey = null
    ): ?StockMovement {
        $cleanNotes = trim($notes);
        if ($cleanNotes === '') {
            throw new StockReasonRequiredException('Adjustment reason note is required.');
        }

        if (! in_array($mode, ['counted', 'difference'], true)) {
            throw new InvalidStockQuantityException("Unsupported adjustment mode: '{$mode}'.");
        }

        $parsedMilli = $mode === 'counted'
            ? self::parseQuantityToMilli($quantity, allowNegative: false)
            : self::parseQuantityToMilli($quantity, allowNegative: true);

        $cleanKey = $idempotencyKey !== null && trim($idempotencyKey) !== '' ? trim($idempotencyKey) : null;

        try {
            return DB::transaction(function () use (
                $ingredient,
                $user,
                $mode,
                $parsedMilli,
                $cleanNotes,
                $cleanKey
            ): ?StockMovement {
                if ($cleanKey !== null) {
                    $existing = StockMovement::where('idempotency_key', $cleanKey)
                        ->where('ingredient_id', $ingredient->id)
                        ->first();
                    if ($existing) {
                        return $existing;
                    }
                }

                $balance = $this->lockOrCreateBalance($ingredient);
                $beforeMilli = $balance->current_stock_milli;

                $deltaMilli = $mode === 'counted'
                    ? ($parsedMilli - $beforeMilli)
                    : $parsedMilli;

                if ($deltaMilli === 0) {
                    return null;
                }

                $afterMilli = $beforeMilli + $deltaMilli;
                if ($afterMilli < 0) {
                    throw new InsufficientStockException('Adjustment would result in negative stock.');
                }

                $balance->setCurrentStockMilli($afterMilli);
                $balance->save();

                return StockMovement::create([
                    'ulid' => (string) Str::ulid(),
                    'branch_id' => null,
                    'ingredient_id' => $ingredient->id,
                    'user_id' => $user->id,
                    'movement_type' => StockMovement::TYPE_ADJUSTMENT,
                    'quantity' => Ingredient::fromMilli($deltaMilli),
                    'balance_before' => Ingredient::fromMilli($beforeMilli),
                    'balance_after' => Ingredient::fromMilli($afterMilli),
                    'unit_cost' => (int) $ingredient->cost_per_unit,
                    'idempotency_key' => $cleanKey,
                    'notes' => $cleanNotes,
                ]);
            });
        } catch (QueryException $e) {
            if ($cleanKey !== null) {
                $existing = StockMovement::where('idempotency_key', $cleanKey)
                    ->where('ingredient_id', $ingredient->id)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            throw $e;
        }
    }

    /**
     * Execute a signed stock mutation inside a locked DB transaction.
     *
     * @throws InsufficientStockException
     */
    private function applySignedMutation(
        Ingredient $ingredient,
        User $user,
        string $movementType,
        int $deltaMilli,
        int $unitCost,
        ?string $notes,
        ?string $idempotencyKey
    ): StockMovement {
        try {
            return DB::transaction(function () use (
                $ingredient,
                $user,
                $movementType,
                $deltaMilli,
                $unitCost,
                $notes,
                $idempotencyKey
            ): StockMovement {
                if ($idempotencyKey !== null) {
                    $existing = StockMovement::where('idempotency_key', $idempotencyKey)
                        ->where('ingredient_id', $ingredient->id)
                        ->first();
                    if ($existing) {
                        return $existing;
                    }
                }

                $balance = $this->lockOrCreateBalance($ingredient);
                $beforeMilli = $balance->current_stock_milli;
                $afterMilli = $beforeMilli + $deltaMilli;

                if ($afterMilli < 0) {
                    throw new InsufficientStockException('Insufficient stock for this operation.');
                }

                $balance->setCurrentStockMilli($afterMilli);
                $balance->save();

                return StockMovement::create([
                    'ulid' => (string) Str::ulid(),
                    'branch_id' => null,
                    'ingredient_id' => $ingredient->id,
                    'user_id' => $user->id,
                    'movement_type' => $movementType,
                    'quantity' => Ingredient::fromMilli($deltaMilli),
                    'balance_before' => Ingredient::fromMilli($beforeMilli),
                    'balance_after' => Ingredient::fromMilli($afterMilli),
                    'unit_cost' => $unitCost,
                    'idempotency_key' => $idempotencyKey,
                    'notes' => $notes,
                ]);
            });
        } catch (QueryException $e) {
            if ($idempotencyKey !== null) {
                $existing = StockMovement::where('idempotency_key', $idempotencyKey)
                    ->where('ingredient_id', $ingredient->id)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            throw $e;
        }
    }

    /**
     * Ensure the single-store (branch_id = NULL) balance row exists and lock it for update.
     */
    private function lockOrCreateBalance(Ingredient $ingredient): IngredientBalance
    {
        $balance = IngredientBalance::firstOrCreate(
            ['branch_id' => null, 'ingredient_id' => $ingredient->id],
            ['current_stock' => '0.000']
        );

        return IngredientBalance::where('id', $balance->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Low-level posting helper for automatic order deductions inside an active locked transaction.
     * Allows resulting balance_after to go below zero without blocking payment settlement.
     */
    public function recordOrderDeductionRow(
        IngredientBalance $balance,
        Ingredient $ingredient,
        int $deductMilli,
        int $userId,
        int $orderId,
        string $idempotencyKey,
        string $notes
    ): StockMovement {
        $beforeMilli = $balance->current_stock_milli;
        $deltaMilli = -$deductMilli;
        $afterMilli = $beforeMilli + $deltaMilli;

        $balance->setCurrentStockMilli($afterMilli);
        $balance->save();

        return StockMovement::create([
            'ulid' => (string) Str::ulid(),
            'branch_id' => null,
            'ingredient_id' => $ingredient->id,
            'user_id' => $userId,
            'movement_type' => StockMovement::TYPE_ORDER_DEDUCTION,
            'quantity' => Ingredient::fromMilli($deltaMilli),
            'balance_before' => Ingredient::fromMilli($beforeMilli),
            'balance_after' => Ingredient::fromMilli($afterMilli),
            'unit_cost' => (int) $ingredient->cost_per_unit,
            'reference_type' => 'orders',
            'reference_id' => $orderId,
            'idempotency_key' => $idempotencyKey,
            'notes' => $notes,
        ]);
    }

    /**
     * Low-level posting helper for full-refund restock credits inside an active locked transaction.
     */
    public function recordRefundCreditRow(
        IngredientBalance $balance,
        int $ingredientId,
        int $creditMilli,
        int $unitCost,
        int $userId,
        int $orderId,
        string $idempotencyKey,
        string $notes
    ): StockMovement {
        $beforeMilli = $balance->current_stock_milli;
        $afterMilli = $beforeMilli + $creditMilli;

        $balance->setCurrentStockMilli($afterMilli);
        $balance->save();

        return StockMovement::create([
            'ulid' => (string) Str::ulid(),
            'branch_id' => null,
            'ingredient_id' => $ingredientId,
            'user_id' => $userId,
            'movement_type' => StockMovement::TYPE_REFUND_CREDIT,
            'quantity' => Ingredient::fromMilli($creditMilli),
            'balance_before' => Ingredient::fromMilli($beforeMilli),
            'balance_after' => Ingredient::fromMilli($afterMilli),
            'unit_cost' => $unitCost,
            'reference_type' => 'orders',
            'reference_id' => $orderId,
            'idempotency_key' => $idempotencyKey,
            'notes' => $notes,
        ]);
    }
}
