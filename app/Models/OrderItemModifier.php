<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemModifier extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'order_item_id',
        'modifier_id',
        'modifier_group_id',
        'group_name_id',
        'group_name_en',
        'modifier_name_id',
        'modifier_name_en',
        'unit_price',
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
        ];
    }

    /**
     * The line item this modifier is attached to.
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * The catalog modifier referenced (nullable if deleted).
     */
    public function modifier(): BelongsTo
    {
        return $this->belongsTo(Modifier::class)->withTrashed();
    }

    /**
     * The catalog modifier group referenced (nullable if deleted).
     */
    public function modifierGroup(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class)->withTrashed();
    }

    /**
     * Get localized modifier option label.
     */
    public function getModifierNameAttribute(): string
    {
        $locale = app()->getLocale();

        return ($locale === 'en' && ! empty($this->modifier_name_en))
            ? $this->modifier_name_en
            : $this->modifier_name_id;
    }

    /**
     * Get localized modifier group label.
     */
    public function getGroupNameAttribute(): string
    {
        $locale = app()->getLocale();

        return ($locale === 'en' && ! empty($this->group_name_en))
            ? $this->group_name_en
            : $this->group_name_id;
    }
}
