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
        // --- 1. ingredient_categories ---
        Schema::create('ingredient_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // --- 2. ingredients ---
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')
                ->nullable()
                ->constrained('ingredient_categories')
                ->nullOnDelete();
            $table->string('sku', 100)->unique();
            $table->string('name', 200);
            $table->string('unit', 20);
            $table->unsignedBigInteger('cost_per_unit')->default(0);
            $table->decimal('min_stock_alert', 12, 3)->default('0.000');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('category_id');
            $table->index('is_active');
        });

        // --- 3. ingredient_balances ---
        // NULL-safe unique: branch_slot = COALESCE(branch_id, 0)
        Schema::create('ingredient_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->foreignId('ingredient_id')
                ->constrained('ingredients')
                ->cascadeOnDelete();
            $table->decimal('current_stock', 12, 3)->default('0.000');
            $table->timestamps();

            $table->index('current_stock');
            $table->index('branch_id');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement(
                'ALTER TABLE `ingredient_balances`
                 ADD COLUMN `branch_slot` BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(`branch_id`, 0)) STORED,
                 ADD UNIQUE KEY `uk_branch_ingredient` (`branch_slot`, `ingredient_id`)'
            );
        } elseif ($driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX uk_branch_ingredient
                 ON ingredient_balances (COALESCE(branch_id, 0), ingredient_id)'
            );
        }

        // --- 4. recipe_items ---
        // NULL-safe unique: variant_slot = COALESCE(product_variant_id, 0)
        Schema::create('recipe_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->foreignId('product_variant_id')
                ->nullable()
                ->constrained('product_variants')
                ->cascadeOnDelete();
            $table->foreignId('ingredient_id')
                ->constrained('ingredients')
                ->cascadeOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->timestamps();
        });

        if ($driver === 'mysql') {
            DB::statement(
                'ALTER TABLE `recipe_items`
                 ADD COLUMN `variant_slot` BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(`product_variant_id`, 0)) STORED,
                 ADD UNIQUE KEY `uk_recipe_item` (`product_id`, `variant_slot`, `ingredient_id`)'
            );
        } elseif ($driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX uk_recipe_item
                 ON recipe_items (product_id, COALESCE(product_variant_id, 0), ingredient_id)'
            );
        }

        // --- 5. modifier_recipe_items ---
        Schema::create('modifier_recipe_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modifier_id')
                ->constrained('modifiers')
                ->cascadeOnDelete();
            $table->foreignId('ingredient_id')
                ->constrained('ingredients')
                ->cascadeOnDelete();
            $table->string('action', 20)->default('add');
            $table->foreignId('replaces_ingredient_id')
                ->nullable()
                ->constrained('ingredients')
                ->nullOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->timestamps();

            $table->unique(['modifier_id', 'ingredient_id'], 'uk_modifier_ingredient');
        });

        // --- 6. stock_movements ---
        // Append-only: no updated_at. Idempotency per (idempotency_key, ingredient_id).
        // NULL idempotency_key allowed for non-idempotent adjustments.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->foreignId('ingredient_id')
                ->constrained('ingredients');
            $table->foreignId('user_id')
                ->constrained('users');
            $table->string('movement_type', 30);
            $table->decimal('quantity', 12, 3);
            $table->decimal('balance_before', 12, 3);
            $table->decimal('balance_after', 12, 3);
            $table->unsignedBigInteger('unit_cost')->default(0);
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(
                ['idempotency_key', 'ingredient_id'],
                'uk_stock_idempotency_ingredient'
            );
            $table->index(['ingredient_id', 'created_at'], 'idx_movements_ingredient_date');
            $table->index(['reference_type', 'reference_id'], 'idx_movements_reference');
            $table->index('branch_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('modifier_recipe_items');
        Schema::dropIfExists('recipe_items');
        Schema::dropIfExists('ingredient_balances');
        Schema::dropIfExists('ingredients');
        Schema::dropIfExists('ingredient_categories');
    }
};
