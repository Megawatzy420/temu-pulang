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
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->string('printer_name', 100)->nullable()->after('printer_connection');
            $table->boolean('printer_enabled')->default(true)->after('printer_name');
            $table->boolean('kitchen_printer_enabled')->default(false)->after('printer_enabled');
            $table->string('kitchen_printer_name', 100)->nullable()->after('kitchen_printer_enabled');

            // Alter defaults for tax, service charge, and printer connection
            $table->unsignedInteger('tax_rate')->default(0)->change();
            $table->unsignedInteger('service_charge_rate')->default(0)->change();
            $table->string('printer_connection', 30)->default('usb')->change();
            $table->unsignedInteger('printer_port')->nullable()->change();
        });

        // Safely update existing default row to align with confirmed requirements: 0% tax, 0% service charge, USB printer
        DB::table('shop_settings')->where('id', 1)->update([
            'tax_rate' => 0,
            'service_charge_rate' => 0,
            'printer_connection' => 'usb',
            'printer_name' => 'POS-80',
            'printer_enabled' => true,
            'kitchen_printer_enabled' => false,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->dropColumn([
                'printer_name',
                'printer_enabled',
                'kitchen_printer_enabled',
                'kitchen_printer_name',
            ]);

            $table->unsignedInteger('tax_rate')->default(1000)->change();
            $table->string('printer_connection', 30)->default('network')->change();
        });
    }
};
