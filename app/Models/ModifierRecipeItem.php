<?php

namespace App\Models;

use Database\Factories\ModifierRecipeItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModifierRecipeItem extends Model
{
    /** @use HasFactory<ModifierRecipeItemFactory> */
    use HasFactory;

    /** Adds an ingredient quantity on top of the base recipe. */
    public const ACTION_ADD = 'add';

    /** Replaces one ingredient with another (e.g. oat milk instead of regular milk). */
    public const ACTION_REPLACE = 'replace';

    protected $fillable = [
        'modifier_id',
        'ingredient_id',
        'action',
        'replaces_ingredient_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'replaces_ingredient_id' => 'integer',
            'quantity' => 'string',  // decimal string – never float
        ];
    }

    // -------------------------------------------------------------------------
    // Milli-unit accessors
    // -------------------------------------------------------------------------

    /**
     * Get quantity as integer milli-units.
     */
    public function getQuantityMilliAttribute(): int
    {
        return Ingredient::toMilli($this->quantity ?? '0.000');
    }

    /**
     * Set quantity from integer milli-units.
     */
    public function setQuantityMilli(int $milli): void
    {
        $this->quantity = Ingredient::fromMilli($milli);
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function modifier(): BelongsTo
    {
        return $this->belongsTo(Modifier::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function replacesIngredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'replaces_ingredient_id');
    }
}
