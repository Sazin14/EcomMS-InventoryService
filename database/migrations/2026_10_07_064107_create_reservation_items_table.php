<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reservation_id')
                ->constrained('reservations')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->unsignedBigInteger('variant_id');
            $table->string('sku', 100);

            $table->foreignId('warehouse_id')
                ->constrained('warehouses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->unsignedBigInteger('quantity');

            $table->timestamps();

            $table->index('variant_id');
            $table->index('sku');
            $table->index('warehouse_id');

            $table->unique(
                ['reservation_id', 'variant_id', 'warehouse_id'],
                'reservation_items_unique_variant_warehouse'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_items');
    }
};
