<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();

            /*
             * These belong to Product Service.
             * There is intentionally NO foreign key.
             */
            $table->unsignedBigInteger('variant_id');
            $table->string('sku', 100);

            /*
             * This belongs to Inventory Service.
             */
            $table->foreignId('warehouse_id')
                ->constrained('warehouses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->unsignedBigInteger('on_hand')->default(0);
            $table->unsignedBigInteger('reserved')->default(0);
            $table->unsignedBigInteger('reorder_level')->default(0);

            $table->timestamps();

            /*
             * One stock row per variant per warehouse.
             */
            $table->unique(
                ['variant_id', 'warehouse_id'],
                'stock_levels_variant_warehouse_unique'
            );

            $table->index('variant_id');
            $table->index('sku');
            $table->index('warehouse_id');
            $table->index('reorder_level');
        });

        /*
         * PostgreSQL-level integrity constraints.
         */
        DB::statement('
            ALTER TABLE stock_levels
            ADD CONSTRAINT stock_levels_reserved_lte_on_hand
            CHECK (reserved <= on_hand)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
    }
};
