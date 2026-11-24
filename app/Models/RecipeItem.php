<?php

namespace App\Models;

use Database\Factories\RecipeItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeItem extends Model
{
    /** @use HasFactory<RecipeItemFactory> */
    use HasFactory;

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'ingredient_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
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

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
