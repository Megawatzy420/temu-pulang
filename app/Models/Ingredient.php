<?php

namespace App\Models;

use Database\Factories\IngredientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

class Ingredient extends Model
{
    /** @use HasFactory<IngredientFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'category_id',
        'sku',
        'name',
        'unit',
        'cost_per_unit',
        'min_stock_alert',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'cost_per_unit' => 'integer',
            'min_stock_alert' => 'string',   // decimal string – never float
            'is_active' => 'boolean',
        ];
    }

    // -------------------------------------------------------------------------
    // Milli-unit helpers (exact integer arithmetic, no PHP float)
    // 1 unit = 1 000 milli-units  →  DECIMAL(12,3) stored as "X.YYY"
    // -------------------------------------------------------------------------

    /**
     * Convert a decimal quantity string (e.g. "1.500") to integer milli-units.
     * Accepts integer strings as well (e.g. "2" → 2000).
     *
     * @throws InvalidArgumentException on malformed input
     */
    public static function toMilli(string|int $value): int
    {
        $str = ltrim((string) $value);

        // Allow optional leading minus sign
        $negative = false;
        if (str_starts_with($str, '-')) {
            $negative = true;
            $str = substr($str, 1);
        }

        if (! preg_match('/^\d+(\.\d{1,3})?$/', $str)) {
            throw new InvalidArgumentException("Invalid decimal quantity: '{$value}'");
        }

        if (str_contains($str, '.')) {
            [$whole, $frac] = explode('.', $str);
            $frac = str_pad($frac, 3, '0', STR_PAD_RIGHT);
        } else {
            $whole = $str;
            $frac = '000';
        }

        $milli = (int) $whole * 1000 + (int) $frac;

        return $negative ? -$milli : $milli;
    }

    /**
     * Convert integer milli-units back to a 3-decimal-place string.
     * e.g. 1500 → "1.500", -500 → "-0.500"
     */
    public static function fromMilli(int $milli): string
    {
        $negative = $milli < 0;
        $abs = abs($milli);
        $whole = intdiv($abs, 1000);
        $frac = $abs % 1000;

        return ($negative ? '-' : '').sprintf('%d.%03d', $whole, $frac);
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function category(): BelongsTo
    {
        return $this->belongsTo(IngredientCategory::class, 'category_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(IngredientBalance::class);
    }

    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    public function modifierRecipeItems(): HasMany
    {
        return $this->hasMany(ModifierRecipeItem::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * @param  Builder<Ingredient>  $query
     * @return Builder<Ingredient>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
