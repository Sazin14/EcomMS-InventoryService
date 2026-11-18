<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preorders', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 64);
            $t->unsignedBigInteger('variant_id');
            $t->unsignedInteger('quantity');
            $t->string('status', 20)->default('pending');
            $t->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('allocated_at')->nullable();
            $t->timestamps();

            $t->unique(['reference', 'variant_id']);
            $t->index(['variant_id', 'status']);
            $t->foreign('variant_id')->references('variant_id')->on('inventory_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preorders');
    }
};
