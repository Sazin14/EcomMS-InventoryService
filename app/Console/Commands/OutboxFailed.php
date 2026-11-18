<?php

namespace App\Console\Commands;

use App\Models\OutboxMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class OutboxFailed extends Command
{
    protected $signature = 'outbox:failed {--retry : put the failed events back in the queue}';

    protected $description = 'Show (or re-queue) outbox events that failed too many times and were set aside';

    public function handle(): int
    {
        $failed = OutboxMessage::whereNotNull('failed_at')->whereNull('published_at');

        if ($this->option('retry')) {
            $count = (clone $failed)->update(['failed_at' => null, 'attempts' => 0, 'available_at' => now()]);
            $this->info("Re-queued {$count} event(s).");

            return self::SUCCESS;
        }

        $rows = (clone $failed)->orderBy('id')->limit(50)->get(['id', 'routing_key', 'attempts', 'failed_at', 'last_error']);

        if ($rows->isEmpty()) {
            $this->info('No failed events.');

            return self::SUCCESS;
        }

        $this->table(['id', 'routing_key', 'attempts', 'failed_at', 'last_error'], $rows->map->only(['id', 'routing_key', 'attempts', 'failed_at', 'last_error'])->all());
        Log::warning('Outbox has events that could not be published.', ['count' => $failed->count()]);

        return self::FAILURE;   // non-zero exit so a monitor / cron can alert
    }
}
