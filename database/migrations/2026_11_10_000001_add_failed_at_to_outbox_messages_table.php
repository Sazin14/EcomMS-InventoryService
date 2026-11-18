<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('outbox_messages', 'failed_at')) {
            return;
        }

        Schema::table('outbox_messages', function (Blueprint $t) {
            $t->timestamp('failed_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('outbox_messages', 'failed_at')) {
            return;
        }

        Schema::table('outbox_messages', function (Blueprint $t) {
            $t->dropColumn('failed_at');
        });
    }
};
