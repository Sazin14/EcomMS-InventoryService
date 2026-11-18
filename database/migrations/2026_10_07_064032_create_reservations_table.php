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

            $table->string('reference', 100);

            $table->string('status', 30)->default('active');

            $table->timestamp('expires_at')->nullable();

            $table->string('idempotency_key', 150)->unique();

            $table->timestamps();

            $table->index('reference');
            $table->index('status');
            $table->index('expires_at');

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
