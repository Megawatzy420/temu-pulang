<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentRefund extends Model
{
    use HasFactory;

    protected $fillable = [
        'refund_transaction_id',
        'payment_id',
        'order_id',
        'shift_id',
        'original_payment_method',
        'refund_method',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    /**
     * Booted method: Enforce strict append-only immutability.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new DomainException('Data refund pembayaran bersifat permanen dan tidak dapat diubah.');
        });

        static::deleting(function () {
            throw new DomainException('Data refund pembayaran bersifat permanen dan tidak dapat dihapus.');
        });
    }

    public function refundTransaction(): BelongsTo
    {
        return $this->belongsTo(RefundTransaction::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
