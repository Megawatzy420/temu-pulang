<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'ulid',
        'payment_transaction_number',
        'daily_sequence',
        'business_date',
        'order_id',
        'shift_id',
        'user_id',
        'cashier_name',
        'total_amount',
        'idempotency_key',
        'request_fingerprint',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'daily_sequence' => 'integer',
            'business_date' => 'date',
            'total_amount' => 'integer',
        ];
    }

    /**
     * Booted method: Enforce strict append-only immutability.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new DomainException('Transaksi pembayaran bersifat permanen dan tidak dapat diubah.');
        });

        static::deleting(function () {
            throw new DomainException('Transaksi pembayaran bersifat permanen dan tidak dapat dihapus.');
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Dynamically computed refund status based on associated refunds.
     */
    public function refundStatus(): string
    {
        $refunded = (int) PaymentRefund::whereIn('payment_id', $this->payments()->select('id'))->sum('amount');

        if ($refunded === 0) {
            return 'none';
        }

        return ($refunded >= $this->total_amount) ? 'refunded' : 'partially_refunded';
    }
}
