<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $t) {
            $t->boolean('flash_sale')->default(false)->index();
            $t->unsignedInteger('flash_limit_per_customer')->nullable();   // max units one customer may hold; null = no limit
        });

        Schema::table('reservations', function (Blueprint $t) {
            $t->unsignedBigInteger('customer_id')->nullable()->index();    // needed to give the per-customer count back
        });
    }

    public function down(): void
    {
        Schema::table('reservations', fn (Blueprint $t) => $t->dropColumn('customer_id'));
        Schema::table('inventory_items', fn (Blueprint $t) => $t->dropColumn(['flash_sale', 'flash_limit_per_customer']));
    }
};
