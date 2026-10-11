<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->string('number', 50)->unique();                       // PO-2026-000001
            $t->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();   // where the goods arrive
            $t->string('status', 30)->default('draft');
            $t->date('expected_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamp('ordered_at')->nullable();
            $t->timestamp('received_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->unsignedBigInteger('created_by_id')->nullable();     // staff id
            $t->timestamps();

            $t->index('status');
            $t->index('supplier_id');
        });

        Schema::create('purchase_order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $t->unsignedBigInteger('variant_id');
            $t->string('sku', 100);                                   // snapshot
            $t->unsignedInteger('quantity_ordered');
            $t->unsignedInteger('quantity_received')->default(0);
            $t->decimal('unit_cost', 12, 2)->nullable();
            $t->timestamps();

            $t->unique(['purchase_order_id', 'variant_id']);
            $t->index('variant_id');
            $t->foreign('variant_id')->references('variant_id')->on('inventory_items')->restrictOnDelete();
        });

        // One row per delivery that arrived (partial deliveries are normal). idempotency_key = no double counting.
        Schema::create('purchase_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $t->string('idempotency_key', 150)->unique();
            $t->text('notes')->nullable();
            $t->string('received_by_type', 50)->nullable();
            $t->unsignedBigInteger('received_by_id')->nullable();
            $t->timestamps();
        });

        Schema::create('purchase_receipt_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_receipt_id')->constrained('purchase_receipts')->cascadeOnDelete();
            $t->foreignId('purchase_order_item_id')->constrained('purchase_order_items')->restrictOnDelete();
            $t->unsignedBigInteger('variant_id');
            $t->string('sku', 100);
            $t->unsignedInteger('quantity');
        });

        // The database refuses impossible numbers and unknown statuses.
        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT purchase_order_items_quantities_valid
            CHECK (quantity_ordered > 0 AND quantity_received >= 0 AND quantity_received <= quantity_ordered)');

        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_status_valid
            CHECK (status IN ('draft', 'ordered', 'partially_received', 'received', 'closed', 'cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_receipt_items');
        Schema::dropIfExists('purchase_receipts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
