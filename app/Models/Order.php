<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    // Order Types
    public const TYPE_DINE_IN = 'dine_in';

    public const TYPE_TAKEAWAY = 'takeaway';

    // Order Statuses
    public const STATUS_DRAFT = 'draft';

    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';

    public const STATUS_PAID = 'paid';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PREPARING = 'preparing';

    public const STATUS_READY = 'ready';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    // Payment Statuses
    public const PAYMENT_STATUS_UNPAID = 'unpaid';

    public const PAYMENT_STATUS_PAID = 'paid';

    public const PAYMENT_STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

    public const PAYMENT_STATUS_REFUNDED = 'refunded';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ulid',
        'order_number',
        'daily_order_number',
        'daily_sequence',
        'business_date',
        'branch_id',
        'shift_id',
        'user_id',
        'order_type',
        'status',
        'subtotal',
        'tax_rate',
        'tax_amount',
        'service_charge_rate',
        'service_charge_amount',
        'discount_amount',
        'total_amount',
        'customer_name',
        'idempotency_key',
        'notes',
        'cancellation_reason',
        'cancelled_by_user_id',
        'cancelled_at',
        'paid_at',
        'completed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'daily_sequence' => 'integer',
            'subtotal' => 'integer',
            'tax_rate' => 'integer',
            'tax_amount' => 'integer',
            'service_charge_rate' => 'integer',
            'service_charge_amount' => 'integer',
            'discount_amount' => 'integer',
            'total_amount' => 'integer',
            'cancelled_at' => 'datetime',
            'paid_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::updating(function (self $order) {
            // Protect financial integrity of paid or terminal orders
            if (in_array($order->getOriginal('status'), ['paid', 'queued', 'preparing', 'ready', 'completed', 'cancelled'], true)) {
                $financialFields = ['subtotal', 'tax_amount', 'service_charge_amount', 'discount_amount', 'total_amount'];
                foreach ($financialFields as $field) {
                    if ($order->isDirty($field)) {
                        throw new DomainException("Kolom finansial {$field} tidak dapat diubah setelah pesanan diproses.");
                    }
                }
            }
        });
    }

    public function getCallNumberAttribute(): string
    {
        return $this->daily_order_number ?? sprintf('TP-%03d', $this->daily_sequence ?? 0);
    }

    /**
     * Line items belonging to this order.
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Historical audit trail of status transitions.
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at');
    }

    /**
     * The register shift during which this order was created.
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * The cashier or staff who created this order.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The owner or supervisor who authorized order cancellation.
     */
    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /**
     * The single parent payment transaction for this order.
     */
    public function paymentTransaction(): HasOne
    {
        return $this->hasOne(PaymentTransaction::class);
    }

    /**
     * The individual payment tenders for this order.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * The refund transactions issued for this order.
     */
    public function refundTransactions(): HasMany
    {
        return $this->hasMany(RefundTransaction::class);
    }

    /**
     * The individual refund allocations for this order.
     */
    public function paymentRefunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    /**
     * QRIS confirmations created for this order.
     */
    public function qrisConfirmations(): HasMany
    {
        return $this->hasMany(QrisConfirmation::class);
    }

    /**
     * Spooler print jobs generated for this order.
     */
    public function printJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }

    // --- Status Helpers ---

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isAwaitingPayment(): bool
    {
        return $this->status === 'awaiting_payment';
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isQueued(): bool
    {
        return $this->status === 'queued';
    }

    public function isPreparing(): bool
    {
        return $this->status === 'preparing';
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, ['draft', 'awaiting_payment'], true);
    }

    // --- Query Scopes ---

    public function scopeAwaitingPayment(Builder $query): Builder
    {
        return $query->where('status', 'awaiting_payment');
    }

    public function scopeKitchenQueue(Builder $query): Builder
    {
        return $query->whereIn('status', ['queued', 'preparing']);
    }

    public function scopeActiveOrders(Builder $query): Builder
    {
        return $query->whereIn('status', ['awaiting_payment', 'paid', 'queued', 'preparing', 'ready']);
    }

    public function scopeToday(Builder $query, ?string $businessDate = null): Builder
    {
        return $query->where('business_date', $businessDate ?? now('Asia/Jakarta')->toDateString());
    }

    public function scopeForShift(Builder $query, int $shiftId): Builder
    {
        return $query->where('shift_id', $shiftId);
    }
}
