<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The "return receipt": what came back, from which order, in what condition, who received it.
        Schema::create('stock_returns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('reservation_id')->constrained('reservations')->restrictOnDelete();
            $t->string('reference', 100)->index();            // order number
            $t->string('idempotency_key', 150)->unique();     // same key = same return, never counted twice
            $t->string('reason')->nullable();                 // customer_refused | not_reachable | wrong_item | defective ...
            $t->string('received_by_type', 50)->nullable();   // staff | order_service
            $t->unsignedBigInteger('received_by_id')->nullable();
            $t->timestamps();
        });

        Schema::create('stock_return_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_return_id')->constrained('stock_returns')->cascadeOnDelete();
            $t->unsignedBigInteger('variant_id')->index();
            $t->string('sku', 100);
            $t->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $t->unsignedBigInteger('quantity');
            $t->string('condition', 20);                      // resellable | damaged
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_return_items');
        Schema::dropIfExists('stock_returns');
    }
};
