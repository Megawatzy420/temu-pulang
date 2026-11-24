<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // 1. Enforce physical database-level constraint: exactly one active shift per branch
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE shifts ADD COLUMN active_branch_slot INT AS (IF(status = 'open', COALESCE(branch_id, 0), NULL)) PERSISTENT, ADD UNIQUE KEY uq_active_shift_per_branch (active_branch_slot)");
        } elseif ($driver === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uq_active_shift_per_branch ON shifts (COALESCE(branch_id, 0)) WHERE status = 'open'");
        }

        // 2. Enforce physical database-level constraint: an original movement can only be reversed ONCE
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->unique('reversed_movement_id', 'uq_reversed_movement_once');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE shifts DROP KEY uq_active_shift_per_branch, DROP COLUMN active_branch_slot');
        } elseif ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS uq_active_shift_per_branch');
        }

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropUnique('uq_reversed_movement_once');
        });
    }
};
