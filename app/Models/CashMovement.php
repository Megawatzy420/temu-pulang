<?php

namespace App\Models;

use Database\Factories\CashMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CashMovement extends Model
{
    /** @use HasFactory<CashMovementFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'shift_id',
        'user_id',
        'type',
        'amount',
        'category',
        'description',
        'approved_by_user_id',
        'is_reversal',
        'reversed_movement_id',
        'reversal_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'is_reversal' => 'boolean',
        ];
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::updating(function (self $movement) {
            throw new \DomainException('Pergerakan kas tidak dapat diubah setelah dicatat.');
        });

        static::deleting(function (self $movement) {
            throw new \DomainException('Pergerakan kas tidak dapat dihapus untuk menjaga integritas audit.');
        });
    }

    /**
     * The shift this movement belongs to.
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * User who entered the movement.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * User who authorized/approved the movement.
     */
    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * The original movement if this record is a reversal.
     */
    public function reversedMovement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_movement_id');
    }

    /**
     * The reversal record if this movement was reversed.
     */
    public function reversalRecord(): HasOne
    {
        return $this->hasOne(self::class, 'reversed_movement_id');
    }

    /**
     * Check if this movement has been reversed.
     */
    public function isReversed(): bool
    {
        return $this->reversalRecord()->exists();
    }

    /**
     * Check if movement is an inflow.
     */
    public function isIn(): bool
    {
        return $this->type === 'in';
    }

    /**
     * Check if movement is an outflow.
     */
    public function isOut(): bool
    {
        return $this->type === 'out';
    }
}
