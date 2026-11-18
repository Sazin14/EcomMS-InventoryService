<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('variant_id')->unique();
            $t->string('sku', 100)->index();
            $t->string('product_name')->nullable();
            $t->string('variant_label')->nullable();
            $t->boolean('allow_preorder')->default(false);
            $t->unsignedInteger('preorder_limit')->nullable();
            $t->date('expected_restock_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
