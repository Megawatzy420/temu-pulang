<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Phase 5: Database Foundation
     * Creates:
     *  - qris_confirmations
     *  - payment_transactions
     *  - payments
     *  - refund_transactions
     *  - payment_refunds
     *  - print_jobs
     * Modifies:
     *  - orders: adds payment_status
     */
    public function up(): void
    {
        // 1. Add payment_status to orders table if not already present
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'payment_status')) {
                $table->string('payment_status', 30)->default('unpaid')->after('status')->index();
            }
        });

        // 2. qris_confirmations Table
        Schema::create('qris_confirmations', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('expected_amount');
            $table->string('reference_number', 100)->nullable()->index();
            $table->timestamp('confirmed_at');
            $table->string('status', 20)->default('pending')->index(); // pending, consumed, expired
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });

        // 3. payment_transactions Table (Parent Checkout Record)
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('payment_transaction_number', 32)->unique();
            $table->unsignedInteger('daily_sequence');
            $table->date('business_date')->index();
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->foreignId('shift_id')->constrained('shifts')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('total_amount');
            $table->string('idempotency_key', 64)->unique();
            $table->char('request_fingerprint', 64)->index();
            $table->string('status', 20)->default('completed')->index();
            $table->timestamps();

            $table->unique(['business_date', 'daily_sequence'], 'uniq_pay_trx_biz_date_seq');
            $table->unique(['business_date', 'payment_transaction_number'], 'uniq_pay_trx_biz_date_num');
            $table->index(['shift_id', 'created_at']);
        });

        // 4. payments Table (Child Tender Records — Append-Only)
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_transaction_id')->constrained('payment_transactions')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('shift_id')->constrained('shifts')->restrictOnDelete();
            $table->string('payment_method', 20)->index(); // cash, qris, debit, credit, transfer
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('tendered_amount');
            $table->unsignedBigInteger('change_amount')->default(0);
            $table->string('reference_number', 100)->nullable();
            $table->foreignId('qris_confirmed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('qris_confirmed_at')->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index(['order_id', 'payment_method']);
            $table->index(['shift_id', 'payment_method']);
        });

        // 5. refund_transactions Table (Parent Refund Request)
        Schema::create('refund_transactions', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('refund_transaction_number', 32)->unique();
            $table->unsignedInteger('daily_sequence');
            $table->date('business_date')->index();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('authorized_by_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('total_refunded_amount');
            $table->text('reason');
            $table->string('idempotency_key', 64)->unique();
            $table->char('request_fingerprint', 64)->index();
            $table->timestamps();

            $table->unique(['business_date', 'daily_sequence'], 'uniq_ref_trx_biz_date_seq');
            $table->unique(['business_date', 'refund_transaction_number'], 'uniq_ref_trx_biz_date_num');
            $table->index(['order_id', 'created_at']);
        });

        // 6. payment_refunds Table (Child Explicit Allocation Lines — Append-Only)
        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_transaction_id')->constrained('refund_transactions')->restrictOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $table->string('original_payment_method', 20);
            $table->string('refund_method', 20)->index(); // cash, qris_reversal, manual_transfer
            $table->unsignedBigInteger('amount');
            $table->timestamps();

            $table->index(['payment_id', 'refund_method']);
            $table->index(['shift_id', 'refund_method']);
        });

        // 7. print_jobs Table (Persistent Queue with Claim-Token Lease Safety)
        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('original_print_job_id')->nullable()->constrained('print_jobs')->nullOnDelete();
            $table->string('job_type', 30)->index(); // customer_receipt, kitchen_ticket, etc.
            $table->string('printer_destination', 100);
            $table->unsignedInteger('paper_width')->default(58);
            $table->boolean('is_reprint')->default(false);
            $table->string('status', 20)->default('pending')->index(); // pending, processing, completed, failed
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->timestamp('locked_at')->nullable()->index();
            $table->char('claim_token', 36)->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'locked_at']);
            $table->index(['order_id', 'job_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
        Schema::dropIfExists('payment_refunds');
        Schema::dropIfExists('refund_transactions');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('qris_confirmations');

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'payment_status')) {
                $table->dropColumn('payment_status');
            }
        });
    }
};
