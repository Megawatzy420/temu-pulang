<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('shop_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->string('name', 150);
            $table->text('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('currency', 10)->default('IDR');
            $table->string('timezone', 50)->default('Asia/Jakarta');
            $table->string('business_day_start_time', 8)->default('06:00:00');
            $table->json('operating_hours')->nullable();
            $table->unsignedInteger('tax_rate')->default(1000); // Basis points: 1000 = 10.00%
            $table->boolean('is_tax_inclusive')->default(false);
            $table->unsignedInteger('service_charge_rate')->default(0); // Basis points: 500 = 5.00%
            $table->text('receipt_header')->nullable();
            $table->text('receipt_footer')->nullable();
            $table->boolean('receipt_show_logo')->default(true);
            $table->boolean('receipt_show_tax')->default(true);
            $table->boolean('receipt_show_customer_info')->default(true);
            $table->string('printer_type', 30)->default('thermal');
            $table->string('printer_paper_width', 20)->default('80mm');
            $table->string('printer_connection', 30)->default('network');
            $table->string('printer_ip', 45)->nullable();
            $table->unsignedInteger('printer_port')->default(9100);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('application_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->string('type', 30)->default('string'); // string, boolean, integer, json
            $table->string('group', 50)->default('general')->index();
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('application_settings');
        Schema::dropIfExists('shop_settings');
    }
};
