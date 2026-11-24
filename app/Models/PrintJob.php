<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrintJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'ulid',
        'order_id',
        'original_print_job_id',
        'job_type',
        'printer_destination',
        'paper_width',
        'is_reprint',
        'status',
        'attempts',
        'max_attempts',
        'locked_at',
        'claim_token',
        'completed_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'paper_width' => 'integer',
            'is_reprint' => 'boolean',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'locked_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function originalPrintJob(): BelongsTo
    {
        return $this->belongsTo(PrintJob::class, 'original_print_job_id');
    }

    public function reprints(): HasMany
    {
        return $this->hasMany(PrintJob::class, 'original_print_job_id');
    }
}
