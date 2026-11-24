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
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->string('shift_number', 50)->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('open')->index(); // 'open', 'closed'
            $table->dateTime('opened_at')->index();
            $table->dateTime('closed_at')->nullable()->index();
            $table->unsignedBigInteger('opening_cash'); // Integer Rupiah
            $table->unsignedBigInteger('expected_cash')->nullable(); // Integer Rupiah
            $table->unsignedBigInteger('actual_cash')->nullable(); // Integer Rupiah
            $table->bigInteger('discrepancy')->nullable(); // Signed Integer Rupiah (actual - expected)
            $table->text('opening_note')->nullable();
            $table->text('closing_note')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['status', 'opened_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
