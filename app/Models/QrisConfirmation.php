<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QrisConfirmation extends Model
{
    use HasFactory;

    protected $fillable = [
        'ulid',
        'order_id',
        'user_id',
        'expected_amount',
        'reference_number',
        'confirmed_at',
        'status',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'expected_amount' => 'integer',
            'confirmed_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isConsumed(): bool
    {
        return $this->status === 'consumed';
    }
}
