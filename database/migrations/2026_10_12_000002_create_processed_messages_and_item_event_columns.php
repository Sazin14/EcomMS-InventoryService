<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_messages', function (Blueprint $t) {
            $t->string('message_id', 36)->primary();
            $t->string('consumer', 100);
            $t->timestamp('processed_at')->index();
        });

        Schema::table('inventory_items', function (Blueprint $t) {
            $t->timestamp('catalog_event_at', 6)->nullable();
            $t->timestamp('discontinued_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $t) {
            $t->dropColumn(['catalog_event_at', 'discontinued_at']);
        });

        Schema::dropIfExists('processed_messages');
    }
};
