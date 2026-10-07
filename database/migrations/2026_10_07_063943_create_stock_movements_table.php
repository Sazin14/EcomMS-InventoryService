<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();

            /*
             * Product Service identifiers.
             * No cross-service foreign keys.
             */
            $table->unsignedBigInteger('variant_id');
            $table->string('sku', 100);

            /*
             * Inventory Service warehouse.
             */
            $table->foreignId('warehouse_id')
                ->constrained('warehouses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            /*
             * Examples:
             *
             * receipt
             * sale
             * return
             * adjustment
             * transfer_in
             * transfer_out
             */
            $table->string('type', 50);

            /*
             * Signed quantity:
             *
             * +100 = stock added
             * -10  = stock removed
             */
            $table->bigInteger('quantity');

            /*
             * What caused this movement?
             *
             * order
             * purchase_order
             * reservation
             * transfer
             * manual_adjustment
             * etc.
             */
            $table->string('reference_type', 100)->nullable();
            $table->string('reference_id', 100)->nullable();

            $table->text('reason')->nullable();

            /*
             * Who/what caused the movement?
             *
             * staff
             * system
             * order_service
             * purchase_service
             * etc.
             */
            $table->string('created_by_type', 50)->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();

            /*
             * Ledger records should be immutable.
             * Therefore we intentionally do NOT use updated_at.
             */
            $table->timestamp('created_at')->useCurrent();

            $table->index(
                ['variant_id', 'warehouse_id'],
                'stock_movements_variant_warehouse_index'
            );

            $table->index(
                ['reference_type', 'reference_id'],
                'stock_movements_reference_index'
            );

            $table->index(
                ['created_by_type', 'created_by_id'],
                'stock_movements_created_by_index'
            );

            $table->index('type');
            $table->index('created_at');
        });

        /*
         * A movement must actually change stock.
         */
        \Illuminate\Support\Facades\DB::statement('
            ALTER TABLE stock_movements
            ADD CONSTRAINT stock_movements_quantity_not_zero
            CHECK (quantity <> 0)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
