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
        // 1. Orders Table
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('order_number', 32)->unique();
            $table->string('daily_order_number', 16);
            $table->unsignedInteger('daily_sequence');
            $table->date('business_date')->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->foreignId('shift_id')->constrained('shifts')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('order_type', 20)->default('dine_in')->index(); // 'dine_in', 'takeaway'
            $table->string('status', 30)->default('draft')->index(); // draft, awaiting_payment, paid, queued, preparing, ready, completed, cancelled
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedInteger('tax_rate')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedInteger('service_charge_rate')->default(0);
            $table->unsignedBigInteger('service_charge_amount')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->string('customer_name', 100)->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->text('notes')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_date', 'daily_order_number'], 'idx_orders_daily_num');
            $table->index(['shift_id', 'status'], 'idx_orders_shift_status');
            $table->index(['status', 'created_at'], 'idx_orders_status_created');
            $table->index(['user_id', 'created_at'], 'idx_orders_user_created');
        });

        // 2. Order Items Table (Frozen Historical Snapshots)
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('sku', 100);
            $table->string('product_name_id', 200);
            $table->string('product_name_en', 200)->nullable();
            $table->string('variant_name_id', 150)->nullable();
            $table->string('variant_name_en', 150)->nullable();
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('modifiers_unit_price')->default(0);
            $table->unsignedBigInteger('final_unit_price');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('subtotal');
            $table->text('special_instructions')->nullable();
            $table->timestamps();

            $table->index('order_id');
        });

        // 3. Order Item Modifiers Table (Frozen Customization Snapshots)
        Schema::create('order_item_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('modifier_id')->nullable()->constrained('modifiers')->nullOnDelete();
            $table->foreignId('modifier_group_id')->nullable()->constrained('modifier_groups')->nullOnDelete();
            $table->string('group_name_id', 150);
            $table->string('group_name_en', 150)->nullable();
            $table->string('modifier_name_id', 150);
            $table->string('modifier_name_en', 150)->nullable();
            $table->unsignedBigInteger('unit_price')->default(0);
            $table->timestamps();

            $table->index('order_item_id');
        });

        // 4. Order Status Histories Table (Audit Trail)
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_item_modifiers');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
