<?php

namespace App\Console\Commands;

use App\Support\RabbitMq\OutboxPublisher;
use Illuminate\Console\Command;
use Throwable;

class PublishOutbox extends Command
{
    protected $signature = 'outbox:publish
        {--once : publish what is waiting, then exit}
        {--sleep=1 : seconds to wait when nothing is waiting}
        {--max-runtime=3600 : exit after this many seconds (Supervisor starts it again, which frees memory)}';

    protected $description = 'Send the events saved in the outbox table to RabbitMQ';

    public function handle(OutboxPublisher $publisher): int
    {
        $started = time();

        do {
            try {
                $count = $publisher->publishBatch();

                if ($count > 0) {
                    $this->info("Published {$count} event(s).");
                }
            } catch (Throwable $e) {
                report($e);
                $this->error('Publishing failed: '.$e->getMessage());
                $publisher->close();
                $count = 0;
                sleep(5);   // RabbitMQ unreachable: the events stay in the table and are sent later
            }

            if ($this->option('once')) {
                break;
            }

            if ($count === 0) {
                sleep(max(1, (int) $this->option('sleep')));
            }
        } while (time() - $started < (int) $this->option('max-runtime'));

        $publisher->close();

        return self::SUCCESS;
    }
}
