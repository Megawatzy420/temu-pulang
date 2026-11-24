<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'product_id',
        'product_variant_id',
        'sku',
        'product_name_id',
        'product_name_en',
        'variant_name_id',
        'variant_name_en',
        'unit_price',
        'modifiers_unit_price',
        'final_unit_price',
        'quantity',
        'subtotal',
        'special_instructions',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'modifiers_unit_price' => 'integer',
            'final_unit_price' => 'integer',
            'quantity' => 'integer',
            'subtotal' => 'integer',
        ];
    }

    /**
     * The parent order this line item belongs to.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The catalog product referenced (nullable if deleted).
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * The catalog variant referenced (nullable if deleted).
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    /**
     * Customization modifiers attached to this line item.
     */
    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderItemModifier::class);
    }

    /**
     * Get the localized product name based on current app locale.
     */
    public function getProductNameAttribute(): string
    {
        $locale = app()->getLocale();

        return ($locale === 'en' && ! empty($this->product_name_en))
            ? $this->product_name_en
            : $this->product_name_id;
    }

    /**
     * Get the localized variant name based on current app locale.
     */
    public function getVariantNameAttribute(): ?string
    {
        $locale = app()->getLocale();

        if (empty($this->variant_name_id)) {
            return null;
        }

        return ($locale === 'en' && ! empty($this->variant_name_en))
            ? $this->variant_name_en
            : $this->variant_name_id;
    }

    /**
     * Full display name including variant (e.g. "Lemon Tea (Large)").
     */
    public function getFullNameAttribute(): string
    {
        $vName = $this->variant_name;

        return $vName ? "{$this->product_name} ({$vName})" : $this->product_name;
    }

    /**
     * Alias for special_instructions.
     */
    public function getNotesAttribute(): ?string
    {
        return $this->special_instructions;
    }
}
