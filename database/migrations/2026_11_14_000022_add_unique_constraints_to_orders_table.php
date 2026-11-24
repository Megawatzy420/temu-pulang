<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds UNIQUE database constraints to the orders table:
     *   1. (business_date, daily_sequence)      — prevents duplicate call-number sequences on the same business day.
     *   2. (business_date, daily_order_number)  — prevents duplicate human-readable call numbers (TP-001 …) on the same business day.
     *
     * Pre-flight: confirms zero conflicting rows exist before applying constraints.
     * Does NOT delete or modify existing data under any circumstance.
     */
    public function up(): void
    {
        // --- Pre-flight duplicate check ---
        $dupSeq = DB::table('orders')
            ->selectRaw('business_date, daily_sequence, COUNT(*) AS cnt')
            ->groupBy('business_date', 'daily_sequence')
            ->having('cnt', '>', 1)
            ->get();

        if ($dupSeq->isNotEmpty()) {
            $details = $dupSeq->map(fn ($r) => "{$r->business_date}:seq={$r->daily_sequence}(×{$r->cnt})")->implode(', ');
            throw new RuntimeException(
                'MIGRATION ABORTED: Duplicate (business_date, daily_sequence) found — cannot add UNIQUE constraint without data loss. '.
                "Resolve conflicts manually before re-running. Conflicts: {$details}"
            );
        }

        $dupNum = DB::table('orders')
            ->selectRaw('business_date, daily_order_number, COUNT(*) AS cnt')
            ->groupBy('business_date', 'daily_order_number')
            ->having('cnt', '>', 1)
            ->get();

        if ($dupNum->isNotEmpty()) {
            $details = $dupNum->map(fn ($r) => "{$r->business_date}:num={$r->daily_order_number}(×{$r->cnt})")->implode(', ');
            throw new RuntimeException(
                'MIGRATION ABORTED: Duplicate (business_date, daily_order_number) found — cannot add UNIQUE constraint without data loss. '.
                "Resolve conflicts manually before re-running. Conflicts: {$details}"
            );
        }

        // --- Drop existing plain composite index if present (replace with unique index) ---
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $existingIndexes = collect(DB::select('SHOW INDEX FROM `orders`'))
                ->pluck('Key_name')
                ->unique()
                ->toArray();

            if (in_array('idx_orders_daily_num', $existingIndexes, true)) {
                DB::statement('ALTER TABLE `orders` DROP INDEX `idx_orders_daily_num`');
            }
        }
        // SQLite does not support ALTER TABLE DROP INDEX in the same way; test database recreates from scratch.

        // --- Add UNIQUE constraints ---
        Schema::table('orders', function (Blueprint $table) {
            $table->unique(['business_date', 'daily_sequence'], 'uniq_orders_biz_date_seq');
            $table->unique(['business_date', 'daily_order_number'], 'uniq_orders_biz_date_num');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('uniq_orders_biz_date_seq');
            $table->dropUnique('uniq_orders_biz_date_num');

            // Restore the original plain composite index
            $table->index(['business_date', 'daily_order_number'], 'idx_orders_daily_num');
        });
    }
};
