<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $t) {
            $t->id();
            $t->uuid('message_id')->unique();
            $t->string('routing_key', 150);
            $t->text('payload');                           // the full JSON envelope, exactly as it will be sent
            $t->timestamp('occurred_at');
            $t->timestamp('published_at')->nullable();     // null = still waiting to be sent
            $t->unsignedInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->timestamp('available_at');                 // do not try before this (retry back-off)
            $t->timestamps();

            $t->index(['published_at', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
