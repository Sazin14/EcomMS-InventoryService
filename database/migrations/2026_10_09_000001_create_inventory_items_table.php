<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per product variant. variant_id comes from ProductService (no cross-database foreign key).
        Schema::create('inventory_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('variant_id')->unique();
            $t->string('sku', 100)->index();
            $t->string('product_name')->nullable();     // snapshot for the dashboard, sent by ProductService
            $t->string('variant_label')->nullable();    // e.g. "8GB / 128GB / Space Grey"
            $t->boolean('allow_preorder')->default(false);
            $t->unsignedInteger('preorder_limit')->nullable();  // max pending pre-order units (null = unlimited)
            $t->date('expected_restock_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
