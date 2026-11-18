<?php

namespace App\Console\Commands;

use App\Models\ProcessedMessage;
use Illuminate\Console\Command;

class PruneProcessedMessages extends Command
{
    protected $signature = 'rabbitmq:prune-processed {--days=14 : keep the "already handled" markers this long}';

    protected $description = 'Delete old processed_messages rows (RabbitMQ will not redeliver an event after that long)';

    public function handle(): int
    {
        $deleted = ProcessedMessage::where('processed_at', '<', now()->subDays((int) $this->option('days')))->delete();

        $this->info("Deleted {$deleted} processed-message marker(s).");

        return self::SUCCESS;
    }
}
