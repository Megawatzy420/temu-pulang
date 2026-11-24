<?php

namespace App\Models;

use App\Traits\HasTranslations;
use Database\Factories\ModifierFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Modifier extends Model
{
    /** @use HasFactory<ModifierFactory> */
    use HasFactory, HasTranslations, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'modifier_group_id',
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
     * Modifier group that owns this modifier.
     */
    public function modifierGroup(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class);
    }

    /**
     * Alias for modifierGroup relationship.
     */
    public function group(): BelongsTo
    {
        return $this->modifierGroup();
    }

    /**
     * Translations for this modifier.
     */
    public function translations(): HasMany
    {
        return $this->hasMany(ModifierTranslation::class);
    }

    /**
     * Ingredient modifications triggered when this modifier is selected.
     */
    public function modifierRecipeItems(): HasMany
    {
        return $this->hasMany(ModifierRecipeItem::class);
    }

    /**
     * Translated name accessor.
     */
    public function getNameAttribute(): ?string
    {
        return $this->getTranslatedAttribute('name');
    }

    /**
     * Check if this modifier is free.
     */
    public function getIsFreeAttribute(): bool
    {
        return $this->price === 0;
    }

    /**
     * Scope query to active modifiers.
     *
     * @param  Builder<Modifier>  $query
     * @return Builder<Modifier>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope query to available (active and not sold out) modifiers.
     *
     * @param  Builder<Modifier>  $query
     * @return Builder<Modifier>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_sold_out', false);
    }

    /**
     * Scope query to free modifiers (price = 0).
     *
     * @param  Builder<Modifier>  $query
     * @return Builder<Modifier>
     */
    public function scopeFree(Builder $query): Builder
    {
        return $query->where('price', 0);
    }

    /**
     * Scope query to paid modifiers (price > 0).
     *
     * @param  Builder<Modifier>  $query
     * @return Builder<Modifier>
     */
    public function scopePaid(Builder $query): Builder
    {
        return $query->where('price', '>', 0);
    }

    /**
     * Scope query to order by sort_order ascending.
     *
     * @param  Builder<Modifier>  $query
     * @return Builder<Modifier>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
