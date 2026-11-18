<?php

namespace App\Console\Commands;

use App\Models\ProcessedMessage;
use App\Support\RabbitMq\EventHandler;
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
 * Long-running worker: php artisan rabbitmq:consume <queue>
 *
 *  - the handler of each queue comes from "consumers" in config/rabbitmq.php
 *
 *  - each event is handled in one DB transaction together with its "already processed" marker
 *  - a failure sends the message to the retry queue (waits, then comes back); after max_attempts it is parked in the .dlq
 *  - parking is confirmed by the broker BEFORE the original message is acknowledged, so nothing is lost
 *  - SIGTERM / Ctrl+C: the current message is finished, then the worker exits
 *  - connection problems stop the command with an error; Supervisor / the orchestrator starts it again
 */
class ConsumeRabbitMq extends Command
{
    protected $signature = 'rabbitmq:consume {queue? : a queue of config("rabbitmq.consumers"); default = the first one} {--max-runtime=3600 : exit after this many seconds (Supervisor restarts it)}';

    protected $description = 'Process events from a RabbitMQ queue';

    private bool $stop = false;

    private bool $returned = false;

    private ?AMQPChannel $parkChannel = null;

    public function handle(RabbitMqConnection $mq): int
    {
        $consumers = (array) config('rabbitmq.consumers', []);
        $queue = (string) ($this->argument('queue') ?? array_key_first($consumers));
        $class = $consumers[$queue] ?? null;

        if (! $class) {
            $this->error("No handler is registered for queue [{$queue}]. Add it to \"consumers\" in config/rabbitmq.php.");

            return self::FAILURE;
        }

        /** @var EventHandler $handler */
        $handler = app($class);

        $channel = $mq->channel();   // also declares the queues, so the worker can start before anything else
        $channel->basic_qos(null, 10, null);   // at most 10 unacknowledged messages at a time

        // A separate channel in "confirm" mode is used to park messages, so waiting for the broker's
        // confirmation never interferes with the deliveries arriving on the consuming channel.
        $this->parkChannel = $mq->extraChannel();
        $this->parkChannel->confirm_select();
        $this->parkChannel->set_return_listener(function () {
            $this->returned = true;
        });

        if (function_exists('pcntl_signal')) {
            foreach ([SIGTERM, SIGINT] as $signal) {
                pcntl_signal($signal, function () {
                    $this->stop = true;
                });
            }
        }

        $channel->basic_consume($queue, '', false, false, false, false,
            fn (AMQPMessage $message) => $this->process($message, $handler, $queue)
        );

        $this->info("Consuming {$queue}. Press Ctrl+C to stop.");
        $started = time();

        while (! $this->stop && $channel->is_consuming() && time() - $started < (int) $this->option('max-runtime')) {
            try {
                $channel->wait(null, false, 5);   // wake up every 5 s to check the stop flag and the runtime limit
            } catch (AMQPTimeoutException) {
                // nothing arrived: normal when idle
            }

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }

        $mq->close();

        return self::SUCCESS;
    }

    private function process(AMQPMessage $message, EventHandler $handler, string $queue): void
    {
        $envelope = json_decode($message->getBody(), true);

        if (! is_array($envelope) || empty($envelope['id']) || empty($envelope['type']) || empty($envelope['occurred_at'])) {
            $this->park($message, $queue, 'Not a valid event envelope.');

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
            $this->retryOrPark($message, $queue, $e);
        }
    }

    private function retryOrPark(AMQPMessage $message, string $queue, Throwable $e): void
    {
        if ($this->failures($message, $queue) + 1 >= (int) config('rabbitmq.max_attempts')) {
            $this->park($message, $queue, Str::limit($e->getMessage(), 200));

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

    /**
     * Put the message in <queue>.dlq for a human, and stop retrying it.
     * The original is acknowledged only after the broker CONFIRMED the copy; if that fails the exception stops
     * the worker, the original is redelivered and nothing is lost.
     */
    private function park(AMQPMessage $message, string $queue, string $reason): void
    {
        $this->returned = false;

        $this->parkChannel->basic_publish(
            new AMQPMessage($message->getBody(), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => new AMQPTable(['x-error' => $reason, 'x-parked-at' => now()->toIso8601String()]),
            ]),
            '',               // default exchange: routes straight to the queue named below
            "{$queue}.dlq",
            true              // mandatory: fail loudly if the dead-letter queue does not exist
        );

        $this->parkChannel->wait_for_pending_acks_returns(5);

        if ($this->returned) {
            throw new \RuntimeException("Dead-letter queue {$queue}.dlq does not exist; message NOT parked.");
        }

        $message->ack();
        $this->warn("PARKED in {$queue}.dlq: {$reason}");
    }
}
