<?php

namespace App\Console\Commands;

use App\Support\RabbitMq\RabbitMqConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Looks after the dead-letter queue of a consumer queue.
 *
 *   php artisan rabbitmq:dlq status                 how many messages wait in the queue / retry / dlq (logs a warning if the dlq is not empty)
 *   php artisan rabbitmq:dlq peek   --limit=10      show the first messages (id, type, error); nothing is removed
 *   php artisan rabbitmq:dlq replay --limit=100     send parked messages back to the main queue (they get a fresh set of attempts)
 *   php artisan rabbitmq:dlq purge  --limit=100     delete parked messages for good
 */
class DeadLetterQueue extends Command
{
    protected $signature = 'rabbitmq:dlq {action=status : status|peek|replay|purge} {queue? : consumer queue; default = the first one in config("rabbitmq.consumers")}
        {--limit=100 : how many messages to handle}
        {--fail-on-dlq : status exits with an error code when the dlq is not empty (for monitors)}';

    protected $description = 'Inspect, replay or purge the dead-letter queue of a consumer queue';

    public function handle(RabbitMqConnection $mq): int
    {
        $queue = (string) ($this->argument('queue') ?? array_key_first((array) config('rabbitmq.consumers', [])));
        $dlq = "{$queue}.dlq";
        $channel = $mq->channel();

        return match ($this->argument('action')) {
            'status' => $this->status($channel, $queue),
            'peek' => $this->peek($channel, $dlq),
            'replay' => $this->move($mq, $dlq, $queue, replay: true),
            'purge' => $this->move($mq, $dlq, $queue, replay: false),
            default => $this->invalid(),
        };
    }

    private function status($channel, string $queue): int
    {
        $rows = [];

        foreach ([$queue, "{$queue}.retry", "{$queue}.dlq"] as $name) {
            [, $messages, $consumers] = $channel->queue_declare($name, true);   // passive: only looks, never changes
            $rows[] = [$name, $messages, $consumers];
        }

        $this->table(['queue', 'messages', 'consumers'], $rows);

        $dead = $rows[2][1];

        if ($rows[0][2] === 0) {
            $this->warn("Nobody is consuming {$queue}: is rabbitmq:consume running?");
            Log::warning("RabbitMQ queue {$queue} has no consumer.");
        }

        if ($dead > 0) {
            $this->warn("{$dead} message(s) are parked in {$queue}.dlq. Look with: php artisan rabbitmq:dlq peek");
            Log::warning("RabbitMQ dead-letter queue {$queue}.dlq holds {$dead} message(s).");

            return $this->option('fail-on-dlq') ? self::FAILURE : self::SUCCESS;
        }

        return self::SUCCESS;
    }

    private function peek($channel, string $dlq): int
    {
        $limit = (int) $this->option('limit');
        $seen = [];

        for ($i = 0; $i < $limit; $i++) {
            $message = $channel->basic_get($dlq);

            if ($message === null) {
                break;
            }

            $seen[] = $message;
            $envelope = json_decode($message->getBody(), true) ?: [];
            $headers = $message->has('application_headers') ? $message->get('application_headers')->getNativeData() : [];

            $this->line(sprintf('%s  %s  %s', $envelope['id'] ?? '?', $envelope['type'] ?? '?', $headers['x-error'] ?? ''));
        }

        // Put them back untouched (requeue). Only the position inside the dlq changes.
        foreach ($seen as $message) {
            $message->nack(true);
        }

        $this->info(count($seen).' message(s) shown.');

        return self::SUCCESS;
    }

    /** replay: publish back to the main queue (confirmed) then remove from the dlq; purge: just remove. */
    private function move(RabbitMqConnection $mq, string $dlq, string $queue, bool $replay): int
    {
        $channel = $mq->channel();
        $out = $mq->extraChannel();
        $out->confirm_select();

        $returned = false;
        $out->set_return_listener(function () use (&$returned) {
            $returned = true;
        });

        $done = 0;

        for ($i = 0; $i < (int) $this->option('limit'); $i++) {
            $message = $channel->basic_get($dlq);

            if ($message === null) {
                break;
            }

            if ($replay) {
                $returned = false;
                $out->basic_publish(
                    new AMQPMessage($message->getBody(), [
                        'content_type' => 'application/json',
                        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                        'application_headers' => new AMQPTable(['x-replayed-at' => now()->toIso8601String()]),
                    ]),
                    '',      // default exchange -> straight into the main queue, with a fresh set of attempts
                    $queue,
                    true
                );
                $out->wait_for_pending_acks_returns(5);

                if ($returned) {
                    $message->nack(true);
                    $this->error("Main queue {$queue} does not exist; stopped.");

                    return self::FAILURE;
                }
            }

            $message->ack();
            $done++;
        }

        $mq->close();
        $this->info(($replay ? 'Replayed ' : 'Purged ')."{$done} message(s).");

        return self::SUCCESS;
    }

    private function invalid(): int
    {
        $this->error('Action must be status, peek, replay or purge.');

        return self::INVALID;
    }
}
