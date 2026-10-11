<?php

namespace App\Console\Commands;

use App\Models\OutboxMessage;
use Illuminate\Console\Command;

class PruneOutbox extends Command
{
    protected $signature = 'outbox:prune {--days=7 : keep published events this long}';

    protected $description = 'Delete outbox rows that were published a while ago';

    public function handle(): int
    {
        $deleted = OutboxMessage::whereNotNull('published_at')
            ->where('published_at', '<', now()->subDays((int) $this->option('days')))
            ->delete();

        $this->info("Deleted {$deleted} published outbox row(s).");

        return self::SUCCESS;
    }
}
