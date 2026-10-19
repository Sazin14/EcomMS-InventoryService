<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();

            /*
             * Usually the Order Service's order ID/reference.
             *
             * Example:
             * ORD-2026-000123
             */
            $table->string('reference', 100);

            /*
             * active
             * confirmed
             * released
             * expired
             * fulfilled
             */
            $table->string('status', 30)->default('active');

            /*
             * Used for automatic expiration.
             */
            $table->timestamp('expires_at')->nullable();

            /*
             * Prevents retrying the same reservation request
             * from reserving stock twice.
             */
            $table->string('idempotency_key', 150)->unique();

            $table->timestamps();

            $table->index('reference');
            $table->index('status');
            $table->index('expires_at');

            /*
             * Useful when looking up:
             *
             * active reservations for an order
             */
            $table->index(
                ['reference', 'status'],
                'reservations_reference_status_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
