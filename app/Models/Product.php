<?php

namespace App\Models;

use App\Traits\HasTranslations;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasTranslations, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'sku',
        'base_price',
        'image_path',
        'has_variants',
        'is_active',
        'is_sold_out',
        'dine_in_available',
        'takeaway_available',
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
            'base_price' => 'integer',
            'has_variants' => 'boolean',
            'is_active' => 'boolean',
            'is_sold_out' => 'boolean',
            'dine_in_available' => 'boolean',
            'takeaway_available' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Category the product belongs to.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Translations for this product.
     */
    public function translations(): HasMany
    {
        return $this->hasMany(ProductTranslation::class);
    }

    /**
     * Variants belonging to this product.
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * Base recipe items for this product (NULL variant = applies to all variants).
     */
    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    /**
     * Modifier groups assigned to this product.
     */
    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class, 'product_modifier_groups')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    /**
     * Translated name accessor.
     */
    public function getNameAttribute(): ?string
    {
        return $this->getTranslatedAttribute('name');
    }

    /**
     * Translated description accessor.
     */
    public function getDescriptionAttribute(): ?string
    {
        return $this->getTranslatedAttribute('description');
    }

    /**
     * Effective price: for products with variants, returns the minimum variant price;
     * otherwise, returns the base price.
     */
    public function getEffectivePriceAttribute(): int
    {
        if ($this->has_variants && $this->relationLoaded('variants') && $this->variants->isNotEmpty()) {
            return (int) $this->variants->min('price');
        }

        if ($this->has_variants) {
            $minPrice = $this->variants()->min('price');

            return $minPrice !== null ? (int) $minPrice : (int) $this->base_price;
        }

        return (int) $this->base_price;
    }

    /**
     * Scope query to active products.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope query to available (active and not sold out) products.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_sold_out', false);
    }

    /**
     * Scope query to products available for dine-in.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeDineIn(Builder $query): Builder
    {
        return $query->where('dine_in_available', true);
    }

    /**
     * Scope query to products available for takeaway.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeTakeaway(Builder $query): Builder
    {
        return $query->where('takeaway_available', true);
    }

    /**
     * Scope query to order by sort_order ascending.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
