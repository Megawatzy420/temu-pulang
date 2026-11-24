<?php

namespace App\Models;

use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'shift_number',
        'user_id',
        'closed_by_user_id',
        'status',
        'opened_at',
        'closed_at',
        'opening_cash',
        'expected_cash',
        'actual_cash',
        'discrepancy',
        'opening_note',
        'closing_note',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'integer',
            'expected_cash' => 'integer',
            'actual_cash' => 'integer',
            'discrepancy' => 'integer',
        ];
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::updating(function (self $shift) {
            if ($shift->getOriginal('status') === 'closed') {
                throw new \DomainException('Shift yang sudah ditutup bersifat permanen dan tidak dapat diubah.');
            }
        });

        static::deleting(function (self $shift) {
            throw new \DomainException('Shift tidak dapat dihapus untuk menjaga integritas audit.');
        });
    }

    /**
     * Cashier who opened the shift.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * User who closed the shift.
     */
    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /**
     * Cash drawer movements recorded during this shift.
     */
    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    /**
     * Orders punched during this register shift.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Payment transactions processed during this register shift.
     */
    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    /**
     * Payment tenders processed during this register shift.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Payment refunds processed during this register shift.
     */
    public function paymentRefunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    /**
     * Determine if shift is currently active/open.
     */
    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /**
     * Determine if shift is closed.
     */
    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    /**
     * Scope query to only open shifts.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    /**
     * Scope query to closed shifts.
     */
    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', 'closed');
    }

    /**
     * Total cash inflows recorded during this shift from manual cash drawer movements.
     */
    public function totalCashIn(): int
    {
        return (int) $this->cashMovements()->where('type', 'in')->sum('amount');
    }

    /**
     * Total cash outflows recorded during this shift from manual cash drawer movements.
     */
    public function totalCashOut(): int
    {
        return (int) $this->cashMovements()->where('type', 'out')->sum('amount');
    }

    /**
     * Total net cash received from sales payments during this shift.
     * Note: Payment.amount represents net cash received (tendered_amount - change_amount).
     */
    public function totalCashPayments(): int
    {
        return (int) $this->payments()->where('payment_method', 'cash')->sum('amount');
    }

    /**
     * Total gross cash tendered by customers during this shift.
     */
    public function totalCashTendered(): int
    {
        return (int) $this->payments()->where('payment_method', 'cash')->sum('tendered_amount');
    }

    /**
     * Total cash change returned to customers during this shift.
     */
    public function totalCashChange(): int
    {
        return (int) $this->payments()->where('payment_method', 'cash')->sum('change_amount');
    }

    /**
     * Total non-cash payments (QRIS, Debit, Credit, Transfer) recorded during this shift.
     * These do not affect physical cash drawer balance.
     */
    public function totalNonCashPayments(): int
    {
        return (int) $this->payments()->whereIn('payment_method', ['qris', 'debit', 'credit', 'transfer'])->sum('amount');
    }

    /**
     * Total cash refunds paid out during this shift.
     * Prepared for Phase 5 Task 5.4 when cash refunds are processed.
     */
    public function totalCashRefunds(): int
    {
        return (int) $this->paymentRefunds()->where('refund_method', 'cash')->sum('amount');
    }

    /**
     * Compute current expected cash balance in drawer.
     * Formula: Opening Cash + Manual Inflows - Manual Outflows + Net Cash Sales - Cash Refunds.
     */
    public function currentExpectedCash(): int
    {
        return (int) (
            $this->opening_cash
            + $this->totalCashIn()
            - $this->totalCashOut()
            + $this->totalCashPayments()
            - $this->totalCashRefunds()
        );
    }

    /**
     * Get complete structured cash reconciliation summary.
     *
     * @return array{
     *     opening_cash: int,
     *     manual_cash_in: int,
     *     manual_cash_out: int,
     *     cash_sales: int,
     *     cash_tendered: int,
     *     cash_change: int,
     *     cash_refunds: int,
     *     non_cash_sales: int,
     *     expected_cash: int
     * }
     */
    public function cashReconciliationSummary(): array
    {
        return [
            'opening_cash' => (int) $this->opening_cash,
            'manual_cash_in' => $this->totalCashIn(),
            'manual_cash_out' => $this->totalCashOut(),
            'cash_sales' => $this->totalCashPayments(),
            'cash_tendered' => $this->totalCashTendered(),
            'cash_change' => $this->totalCashChange(),
            'cash_refunds' => $this->totalCashRefunds(),
            'non_cash_sales' => $this->totalNonCashPayments(),
            'expected_cash' => $this->currentExpectedCash(),
        ];
    }
}
