<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make printer_port nullable.
     * Uses raw SQL for MySQL (doctrine/dbal not installed).
     * SQLite (used in tests) already created the column nullable via RefreshDatabase.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE shop_settings MODIFY COLUMN printer_port INT UNSIGNED NULL DEFAULT NULL');

            // Clear printer_port for USB printer rows (port is irrelevant for USB)
            DB::table('shop_settings')
                ->where('printer_connection', 'usb')
                ->update(['printer_ip' => null, 'printer_port' => null]);
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE shop_settings MODIFY COLUMN printer_port INT UNSIGNED NOT NULL DEFAULT 9100');
        }
    }
};
