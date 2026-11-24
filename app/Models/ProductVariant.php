<?php

namespace App\Models;

use App\Traits\HasTranslations;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory, HasTranslations, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'sku',
        'price',
        'is_active',
        'is_sold_out',
        'sort_order',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'is_active' => 'boolean',
            'is_sold_out' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Product that owns this variant.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Translations for this variant.
     */
    public function translations(): HasMany
    {
        return $this->hasMany(VariantTranslation::class, 'product_variant_id');
    }

    /**
     * Variant-specific recipe items (overrides the base product recipe).
     */
    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class, 'product_variant_id');
    }

    /**
     * Translated name accessor.
     */
    public function getNameAttribute(): ?string
    {
        return $this->getTranslatedAttribute('name');
    }

    /**
     * Scope query to only active variants.
     *
     * @param  Builder<ProductVariant>  $query
     * @return Builder<ProductVariant>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope query to only available (active and not sold out) variants.
     *
     * @param  Builder<ProductVariant>  $query
     * @return Builder<ProductVariant>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_sold_out', false);
    }

    /**
     * Scope query to order by sort_order ascending.
     *
     * @param  Builder<ProductVariant>  $query
     * @return Builder<ProductVariant>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
