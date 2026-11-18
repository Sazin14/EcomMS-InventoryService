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

            $table->unsignedBigInteger('variant_id');
            $table->string('sku', 100);

            $table->foreignId('warehouse_id')
                ->constrained('warehouses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('type', 50);

            $table->bigInteger('quantity');

            $table->string('reference_type', 100)->nullable();
            $table->string('reference_id', 100)->nullable();

            $table->text('reason')->nullable();

            $table->string('created_by_type', 50)->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();

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
