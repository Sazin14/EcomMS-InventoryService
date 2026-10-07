<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The inventory dashboard's notification list.
        Schema::create('inventory_alerts', function (Blueprint $t) {
            $t->id();
            $t->string('type', 30);    // low_stock | out_of_stock | preorder_created | preorder_allocated
            $t->unsignedBigInteger('variant_id');
            $t->string('sku', 100)->nullable();
            $t->integer('quantity')->nullable();
            $t->string('message');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();

            $t->index(['read_at', 'type']);
            $t->index(['variant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_alerts');
    }
};
