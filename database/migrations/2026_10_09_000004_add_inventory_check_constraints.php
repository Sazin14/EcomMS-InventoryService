<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE stock_levels DROP CONSTRAINT IF EXISTS stock_levels_non_negative');
        DB::statement('ALTER TABLE stock_levels ADD CONSTRAINT stock_levels_non_negative CHECK (on_hand >= 0 AND reserved >= 0)');

        DB::statement('ALTER TABLE reservation_items DROP CONSTRAINT IF EXISTS reservation_items_quantity_positive');
        DB::statement('ALTER TABLE reservation_items ADD CONSTRAINT reservation_items_quantity_positive CHECK (quantity > 0)');

        DB::statement('CREATE INDEX IF NOT EXISTS reservations_status_expires_index ON reservations (status, expires_at)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_levels DROP CONSTRAINT IF EXISTS stock_levels_non_negative');
        DB::statement('ALTER TABLE reservation_items DROP CONSTRAINT IF EXISTS reservation_items_quantity_positive');
        DB::statement('DROP INDEX IF EXISTS reservations_status_expires_index');
    }
};
