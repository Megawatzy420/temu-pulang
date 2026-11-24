<?php

namespace App\Models;

use Database\Factories\StockMovementFactory;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use HasFactory;

    /**
     * stock_movements is append-only: no updated_at column.
     */
    public const UPDATED_AT = null;

    // Movement type constants
    public const TYPE_STOCK_IN = 'stock_in';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_ORDER_DEDUCTION = 'order_deduction';

    public const TYPE_REFUND_CREDIT = 'refund_credit';

    public const TYPE_WASTAGE = 'wastage';

    public const TYPE_OPENING_STOCK = 'opening_stock';

    protected $fillable = [
        'ulid',
        'branch_id',
        'ingredient_id',
        'user_id',
        'movement_type',
        'quantity',
        'balance_before',
        'balance_after',
        'unit_cost',
        'reference_type',
        'reference_id',
        'idempotency_key',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'integer',
            'reference_id' => 'integer',
            'quantity' => 'string',       // decimal string – never float
            'balance_before' => 'string',
            'balance_after' => 'string',
        ];
    }

    // -------------------------------------------------------------------------
    // Append-only guard
    // -------------------------------------------------------------------------

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new DomainException('StockMovement records are immutable and cannot be updated.');
        });

        static::deleting(static function (): never {
            throw new DomainException('StockMovement records are immutable and cannot be deleted.');
        });
    }

    // -------------------------------------------------------------------------
    // Milli-unit accessors
    // -------------------------------------------------------------------------

    public function getQuantityMilliAttribute(): int
    {
        return Ingredient::toMilli($this->quantity ?? '0.000');
    }

    public function setQuantityMilli(int $milli): void
    {
        $this->quantity = Ingredient::fromMilli($milli);
    }

    public function getBalanceBeforeMilliAttribute(): int
    {
        return Ingredient::toMilli($this->balance_before ?? '0.000');
    }

    public function getBalanceAfterMilliAttribute(): int
    {
        return Ingredient::toMilli($this->balance_after ?? '0.000');
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
