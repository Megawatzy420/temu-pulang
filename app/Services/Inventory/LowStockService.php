<?php

namespace App\Services\Inventory;

use App\Models\Ingredient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class LowStockService
{
    /**
     * Base query for active, non-deleted ingredients with min_stock_alert > 0
     * whose single-store (branch_id = NULL) balance is <= min_stock_alert.
     *
     * @return Builder<Ingredient>
     */
    public function lowStockQuery(): Builder
    {
        return Ingredient::query()
            ->leftJoin('ingredient_balances', static function ($join): void {
                $join->on('ingredient_balances.ingredient_id', '=', 'ingredients.id')
                    ->whereNull('ingredient_balances.branch_id');
            })
            ->where('ingredients.is_active', true)
            ->where('ingredients.min_stock_alert', '>', 0)
            ->whereRaw('COALESCE(ingredient_balances.current_stock, 0) <= ingredients.min_stock_alert');
    }

    /**
     * Return low-stock and out-of-stock ingredients ordered by:
     * 1) "out" (<= 0) first,
     * 2) smallest margin (current_stock - min_stock_alert) ascending,
     * 3) ingredient name ascending.
     *
     * @return Collection<int, array{
     *     id: int,
     *     sku: string,
     *     name: string,
     *     unit: string,
     *     current_stock: string,
     *     min_stock_alert: string,
     *     level: string
     * }>
     */
    public function lowStockIngredients(?int $limit = null): Collection
    {
        $query = $this->lowStockQuery()
            ->select([
                'ingredients.id',
                'ingredients.sku',
                'ingredients.name',
                'ingredients.unit',
                'ingredients.min_stock_alert',
            ])
            ->selectRaw('COALESCE(ingredient_balances.current_stock, 0) as on_hand_stock')
            ->orderByRaw('CASE WHEN COALESCE(ingredient_balances.current_stock, 0) <= 0 THEN 0 ELSE 1 END ASC')
            ->orderByRaw('(COALESCE(ingredient_balances.current_stock, 0) - ingredients.min_stock_alert) ASC')
            ->orderBy('ingredients.name', 'asc');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()->map(static function (Ingredient $row): array {
            $onHandMilli = Ingredient::toMilli((string) $row->on_hand_stock);
            $thresholdMilli = Ingredient::toMilli((string) $row->min_stock_alert);

            return [
                'id' => (int) $row->id,
                'sku' => (string) $row->sku,
                'name' => (string) $row->name,
                'unit' => (string) $row->unit,
                'current_stock' => Ingredient::fromMilli($onHandMilli),
                'min_stock_alert' => Ingredient::fromMilli($thresholdMilli),
                'level' => $onHandMilli <= 0 ? 'out' : 'low',
            ];
        });
    }

    /**
     * Cheap uncached count of low-stock and out-of-stock ingredients.
     */
    public function count(): int
    {
        return (int) $this->lowStockQuery()->count();
    }
}
