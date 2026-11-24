<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_transaction_id',
        'shift_id',
        'payment_method',
        'amount',
        'tendered_amount',
        'change_amount',
        'reference_number',
        'qris_confirmed_by_user_id',
        'qris_confirmed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'tendered_amount' => 'integer',
            'change_amount' => 'integer',
            'qris_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Booted method: Enforce strict append-only immutability.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new DomainException('Data pembayaran bersifat permanen dan tidak dapat diubah.');
        });

        static::deleting(function () {
            throw new DomainException('Data pembayaran bersifat permanen dan tidak dapat dihapus.');
        });
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function qrisConfirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'qris_confirmed_by_user_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    /**
     * Calculate remaining refundable balance for this specific payment record.
     */
    public function remainingRefundableBalance(): int
    {
        $refunded = (int) $this->refunds()->sum('amount');

        return max(0, $this->amount - $refunded);
    }
}
