<?php

namespace App\Console\Commands;

use App\Models\ProcessedMessage;
use App\Services\CatalogEventHandler;
use App\Support\RabbitMq\RabbitMqConnection;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * Long-running worker: php artisan rabbitmq:consume
 *
 *  - each event is handled in one DB transaction together with its "already processed" marker
 *  - a failure sends the message to the retry queue (waits, then comes back); after max_attempts it is parked in the .dlq
 *  - connection problems stop the command with an error; Supervisor starts it again
 */
class ConsumeRabbitMq extends Command
{
    protected $signature = 'rabbitmq:consume {queue=inventory.catalog} {--max-runtime=3600 : exit after this many seconds (Supervisor restarts it)}';

    protected $description = 'Process events from a RabbitMQ queue';

    public function handle(RabbitMqConnection $mq, CatalogEventHandler $handler): int
    {
        $queue = (string) $this->argument('queue');
        $channel = $mq->channel();
        $channel->basic_qos(null, 10, null);   // at most 10 unacknowledged messages at a time

        $channel->basic_consume($queue, '', false, false, false, false,
            fn (AMQPMessage $message) => $this->process($channel, $message, $handler, $queue)
        );

        $this->info("Consuming {$queue}. Press Ctrl+C to stop.");
        $started = time();

        while ($channel->is_consuming() && time() - $started < (int) $this->option('max-runtime')) {
            try {
                $channel->wait(null, false, 5);   // wake up every 5 s to check the runtime limit
            } catch (AMQPTimeoutException) {
                // nothing arrived: normal when idle
            }
        }

        $mq->close();

        return self::SUCCESS;
    }

    private function process(AMQPChannel $channel, AMQPMessage $message, CatalogEventHandler $handler, string $queue): void
    {
        $envelope = json_decode($message->getBody(), true);

        if (! is_array($envelope) || empty($envelope['id']) || empty($envelope['type']) || empty($envelope['occurred_at'])) {
            $this->park($channel, $message, $queue, 'Not a valid event envelope.');

            return;
        }

        try {
            DB::transaction(function () use ($handler, $envelope, $queue) {
                // Throws a unique-constraint error if this event id was already handled (a redelivery).
                ProcessedMessage::create(['message_id' => $envelope['id'], 'consumer' => $queue, 'processed_at' => now()]);

                $handler->handle($envelope);
            });

            $message->ack();
            $this->line("OK   {$envelope['type']} {$envelope['id']}");
        } catch (Throwable $e) {
            // A unique violation only means "already handled" if the marker really is there;
            // otherwise it came from the handler itself and must be retried.
            if ($e instanceof UniqueConstraintViolationException && ProcessedMessage::whereKey($envelope['id'])->exists()) {
                $message->ack();
                $this->line("SKIP {$envelope['type']} {$envelope['id']} (already processed)");

                return;
            }

            report($e);
            $this->error("FAIL {$envelope['type']} {$envelope['id']}: ".$e->getMessage());
            $this->retryOrPark($channel, $message, $queue, $e);
        }
    }

    private function retryOrPark(AMQPChannel $channel, AMQPMessage $message, string $queue, Throwable $e): void
    {
        if ($this->failures($message, $queue) + 1 >= (int) config('rabbitmq.max_attempts')) {
            $this->park($channel, $message, $queue, Str::limit($e->getMessage(), 200));

            return;
        }

        $message->nack(false);   // requeue = false: dead-lettered into the retry queue, which sends it back after the delay
    }

    /** How many times this message already failed in $queue (RabbitMQ counts it in the x-death header). */
    private function failures(AMQPMessage $message, string $queue): int
    {
        if (! $message->has('application_headers')) {
            return 0;
        }

        $headers = $message->get('application_headers')->getNativeData();

        foreach ($headers['x-death'] ?? [] as $death) {
            if (($death['queue'] ?? null) === $queue && ($death['reason'] ?? null) === 'rejected') {
                return (int) ($death['count'] ?? 0);
            }
        }

        return 0;
    }

    /** Put the message in <queue>.dlq for a human, and stop retrying it. */
    private function park(AMQPChannel $channel, AMQPMessage $message, string $queue, string $reason): void
    {
        $channel->basic_publish(
            new AMQPMessage($message->getBody(), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => new AMQPTable(['x-error' => $reason]),
            ]),
            '',               // default exchange: routes straight to the queue named below
            "{$queue}.dlq"
        );

        $message->ack();
        $this->warn("PARKED in {$queue}.dlq: {$reason}");
    }
}
