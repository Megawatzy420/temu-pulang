<?php

namespace App\Livewire\Inventory;

use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Inventory\InvalidStockQuantityException;
use App\Exceptions\Inventory\StockReasonRequiredException;
use App\Models\ActivityLog;
use App\Models\Ingredient;
use App\Models\IngredientBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\StockAdjustmentService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;

class StockAdjustmentManager extends Component
{
    public string $activeTab = 'stock_in';

    public string $ingredientSearch = '';

    public ?int $selectedIngredientId = null;

    public string $quantity = '';

    public int $unitCost = 0;

    public string $adjustmentMode = 'counted';

    public string $notes = '';

    public string $idempotencyKey = '';

    public ?string $errorMessage = null;

    public ?string $infoMessage = null;

    /**
     * Last recorded movement summary for UI feedback.
     *
     * @var array{ingredient: string, unit: string, quantity: string, balance_before: string, balance_after: string}|null
     */
    public ?array $lastResult = null;

    public function mount(): void
    {
        if (! Auth::user()?->isOwner()) {
            abort(403);
        }

        $this->idempotencyKey = (string) Str::uuid();
    }

    public function switchTab(string $tab): void
    {
        if (! Auth::user()?->isOwner()) {
            abort(403);
        }

        if (in_array($tab, ['stock_in', 'adjustment', 'wastage'], true)) {
            $this->activeTab = $tab;
            $this->errorMessage = null;
            $this->infoMessage = null;
            $this->lastResult = null;
            $this->quantity = '';
            $this->notes = '';
            $this->idempotencyKey = (string) Str::uuid();
        }
    }

    public function selectIngredient(int $ingredientId): void
    {
        if (! Auth::user()?->isOwner()) {
            abort(403);
        }

        $ingredient = Ingredient::find($ingredientId);
        if (! $ingredient) {
            return;
        }

        $this->selectedIngredientId = $ingredient->id;
        $this->unitCost = (int) $ingredient->cost_per_unit;
        $this->errorMessage = null;
        $this->infoMessage = null;
        $this->lastResult = null;
    }

    public function submitStockIn(StockAdjustmentService $service): void
    {
        $user = Auth::user();
        if (! $user || ! $user->isOwner()) {
            abort(403);
        }

        $this->resetFeedback();

        $ingredient = $this->resolveSelectedIngredient();
        if (! $ingredient) {
            return;
        }

        try {
            $movement = $service->stockIn(
                ingredient: $ingredient,
                user: $user,
                quantity: $this->quantity,
                unitCost: $this->unitCost,
                notes: $this->notes,
                idempotencyKey: $this->idempotencyKey,
            );

            $this->handleSuccess($ingredient, $movement, 'stock_in', $user);
        } catch (\Throwable $e) {
            $this->handleException($e, $ingredient);
        }
    }

    public function submitWastage(StockAdjustmentService $service): void
    {
        $user = Auth::user();
        if (! $user || ! $user->isOwner()) {
            abort(403);
        }

        $this->resetFeedback();

        $ingredient = $this->resolveSelectedIngredient();
        if (! $ingredient) {
            return;
        }

        try {
            $movement = $service->recordWastage(
                ingredient: $ingredient,
                user: $user,
                quantity: $this->quantity,
                notes: $this->notes,
                idempotencyKey: $this->idempotencyKey,
            );

            $this->handleSuccess($ingredient, $movement, 'stock_wastage', $user);
        } catch (\Throwable $e) {
            $this->handleException($e, $ingredient);
        }
    }

    public function submitAdjustment(StockAdjustmentService $service): void
    {
        $user = Auth::user();
        if (! $user || ! $user->isOwner()) {
            abort(403);
        }

        $this->resetFeedback();

        $ingredient = $this->resolveSelectedIngredient();
        if (! $ingredient) {
            return;
        }

        try {
            $movement = $service->adjust(
                ingredient: $ingredient,
                user: $user,
                quantity: $this->quantity,
                mode: $this->adjustmentMode,
                notes: $this->notes,
                idempotencyKey: $this->idempotencyKey,
            );

            if ($movement === null) {
                $this->infoMessage = __('messages.stock.nothing_to_change');
                $this->dispatch('notify', message: $this->infoMessage, type: 'info');

                return;
            }

            $this->handleSuccess($ingredient, $movement, 'stock_adjustment', $user);
        } catch (\Throwable $e) {
            $this->handleException($e, $ingredient);
        }
    }

    private function resetFeedback(): void
    {
        $this->errorMessage = null;
        $this->infoMessage = null;
        $this->lastResult = null;
        $this->resetErrorBag();
    }

    private function resolveSelectedIngredient(): ?Ingredient
    {
        $ingredient = $this->selectedIngredientId
            ? Ingredient::find($this->selectedIngredientId)
            : null;

        if (! $ingredient) {
            $this->errorMessage = __('messages.stock.error_ingredient_required');
            $this->dispatch('notify', message: $this->errorMessage, type: 'error');

            return null;
        }

        return $ingredient;
    }

    private function handleSuccess(Ingredient $ingredient, StockMovement $movement, string $auditAction, User $user): void
    {
        ActivityLog::record(
            action: $auditAction,
            description: "Stock movement ({$auditAction}) for {$ingredient->name}: {$movement->quantity} {$ingredient->unit}.",
            subject: $movement,
            properties: [
                'ingredient_id' => $ingredient->id,
                'ingredient_name' => $ingredient->name,
                'movement_type' => $movement->movement_type,
                'quantity' => $movement->quantity,
                'balance_before' => $movement->balance_before,
                'balance_after' => $movement->balance_after,
                'unit_cost' => $movement->unit_cost,
                'notes' => $movement->notes,
                'user_id' => $user->id,
                'user_name' => $user->name,
            ],
            user: $user,
            module: 'inventory'
        );

        $this->lastResult = [
            'ingredient' => $ingredient->name,
            'unit' => $ingredient->unit,
            'quantity' => $movement->quantity,
            'balance_before' => $movement->balance_before,
            'balance_after' => $movement->balance_after,
        ];

        $this->quantity = '';
        $this->notes = '';
        $this->idempotencyKey = (string) Str::uuid();

        $this->dispatch('notify', message: __('messages.stock.success_recorded'), type: 'success');
    }

    private function handleException(\Throwable $e, Ingredient $ingredient): void
    {
        $this->errorMessage = $this->mapExceptionToMessage($e, $ingredient);
        $this->dispatch('notify', message: $this->errorMessage, type: 'error');
    }

    private function mapExceptionToMessage(\Throwable $e, Ingredient $ingredient): string
    {
        if ($e instanceof InvalidStockQuantityException) {
            return __('messages.stock.error_invalid_quantity');
        }

        if ($e instanceof InsufficientStockException) {
            return __('messages.stock.error_insufficient_stock');
        }

        if ($e instanceof StockReasonRequiredException) {
            return __('messages.stock.error_reason_required');
        }

        Log::error('Stock adjustment error: '.$e->getMessage(), [
            'exception' => $e,
            'ingredient_id' => $ingredient->id,
            'user_id' => Auth::id(),
        ]);

        return __('messages.stock.error_generic');
    }

    public function render(): View
    {
        $ingredientsQuery = Ingredient::query()
            ->active()
            ->orderBy('name');

        if (trim($this->ingredientSearch) !== '') {
            $kw = '%'.trim($this->ingredientSearch).'%';
            $ingredientsQuery->where(function ($q) use ($kw) {
                $q->where('name', 'like', $kw)
                    ->orWhere('sku', 'like', $kw);
            });
        }

        $ingredients = $ingredientsQuery->limit(40)->get();

        $balances = IngredientBalance::whereNull('branch_id')
            ->whereIn('ingredient_id', $ingredients->pluck('id'))
            ->pluck('current_stock', 'ingredient_id');

        $selectedIngredient = $this->selectedIngredientId
            ? Ingredient::find($this->selectedIngredientId)
            : null;

        $selectedStock = $selectedIngredient
            ? (IngredientBalance::whereNull('branch_id')
                ->where('ingredient_id', $selectedIngredient->id)
                ->value('current_stock') ?? '0.000')
            : null;

        return view('livewire.inventory.stock-adjustment-manager', [
            'ingredients' => $ingredients,
            'balances' => $balances,
            'selectedIngredient' => $selectedIngredient,
            'selectedStock' => $selectedStock,
        ])->layout('components.layouts.app', [
            'title' => __('messages.stock.title'),
            'headerTitle' => __('messages.stock.title'),
            'headerSubtitle' => __('messages.stock.subtitle'),
        ]);
    }
}
