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
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('module', 50)->nullable()->after('action')->index();
        });

        // Backfill existing rows with module derived from action
        DB::table('activity_logs')->whereNull('module')->get()->each(function ($log) {
            $module = explode('.', $log->action)[0] ?? 'general';
            DB::table('activity_logs')->where('id', $log->id)->update(['module' => $module]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['module']);
            $table->dropColumn('module');
        });
    }
};
