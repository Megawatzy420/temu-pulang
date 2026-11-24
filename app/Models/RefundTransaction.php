<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RefundTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'ulid',
        'refund_transaction_number',
        'daily_sequence',
        'business_date',
        'order_id',
        'authorized_by_user_id',
        'total_refunded_amount',
        'reason',
        'idempotency_key',
        'request_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'daily_sequence' => 'integer',
            'business_date' => 'date',
            'total_refunded_amount' => 'integer',
        ];
    }

    /**
     * Booted method: Enforce strict append-only immutability.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new DomainException('Data transaksi refund bersifat permanen dan tidak dapat diubah.');
        });

        static::deleting(function () {
            throw new DomainException('Data transaksi refund bersifat permanen dan tidak dapat dihapus.');
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function authorizedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by_user_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }
}
