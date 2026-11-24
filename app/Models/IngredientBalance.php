<?php

namespace App\Models;

use Database\Factories\IngredientBalanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngredientBalance extends Model
{
    /** @use HasFactory<IngredientBalanceFactory> */
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'ingredient_id',
        'current_stock',
    ];

    protected function casts(): array
    {
        return [
            'current_stock' => 'string',  // decimal string – never float
        ];
    }

    // -------------------------------------------------------------------------
    // Milli-unit accessors
    // -------------------------------------------------------------------------

    /**
     * Get current_stock as integer milli-units.
     */
    public function getCurrentStockMilliAttribute(): int
    {
        return Ingredient::toMilli($this->current_stock ?? '0.000');
    }

    /**
     * Set current_stock from integer milli-units.
     */
    public function setCurrentStockMilli(int $milli): void
    {
        $this->current_stock = Ingredient::fromMilli($milli);
    }

    // -------------------------------------------------------------------------
    // Business logic
    // -------------------------------------------------------------------------

    /**
     * Whether the current stock is at or below the ingredient's min_stock_alert.
     */
    public function isLowStock(): bool
    {
        $currentMilli = Ingredient::toMilli($this->current_stock ?? '0.000');
        $minMilli = Ingredient::toMilli(
            $this->ingredient?->min_stock_alert ?? '0.000'
        );

        return $currentMilli <= $minMilli;
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
