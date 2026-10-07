<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Needs YOUR reservation_items migration to have run first.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_items', function (Blueprint $t) {
            $t->unsignedBigInteger('returned_quantity')->default(0)->after('quantity');
        });

        // You can never get back more than you shipped.
        DB::statement('ALTER TABLE reservation_items ADD CONSTRAINT reservation_items_returned_valid
            CHECK (returned_quantity >= 0 AND returned_quantity <= quantity)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE reservation_items DROP CONSTRAINT IF EXISTS reservation_items_returned_valid');

        Schema::table('reservation_items', function (Blueprint $t) {
            $t->dropColumn('returned_quantity');
        });
    }
};
