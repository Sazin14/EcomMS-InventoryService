<?php

namespace App\Console\Commands;

use App\Support\RabbitMq\RabbitMqConnection;
use Illuminate\Console\Command;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Creates this service's queues. Run it BEFORE ProductService publishes anything:
 * an event published while no queue is bound to its routing key is simply dropped by RabbitMQ.
 * (If that happens, run  php artisan catalog:resync-inventory  in ProductService.)
 */
class SetupRabbitMq extends Command
{
    protected $signature = 'rabbitmq:setup {--debug : also create a queue that keeps a copy of every inventory.* event, to look at in the management UI}';

    protected $description = 'Create the RabbitMQ queues InventoryService consumes from';

    public function handle(RabbitMqConnection $mq): int
    {
        $channel = $mq->channel();   // declares the exchanges ecom.events and ecom.retry

        $exchange = config('rabbitmq.exchange');
        $retryExchange = config('rabbitmq.retry_exchange');
        $queue = 'inventory.catalog';

        // Main queue. A message that is rejected goes to the retry exchange (and from there to the retry queue).
        $channel->queue_declare($queue, false, true, false, false, false, new AMQPTable([
            'x-dead-letter-exchange' => $retryExchange,
            'x-dead-letter-routing-key' => $queue,
        ]));
        $channel->queue_bind($queue, $exchange, 'catalog.variant.*');

        // Retry queue: holds a failed message for retry_delay_ms, then sends it back to the main queue.
        $channel->queue_declare("{$queue}.retry", false, true, false, false, false, new AMQPTable([
            'x-message-ttl' => (int) config('rabbitmq.retry_delay_ms'),
            'x-dead-letter-exchange' => '',               // "" = the default exchange, which routes by queue name
            'x-dead-letter-routing-key' => $queue,
        ]));
        $channel->queue_bind("{$queue}.retry", $retryExchange, $queue);

        // Dead-letter queue: messages that failed max_attempts times are parked here for a human to look at.
        $channel->queue_declare("{$queue}.dlq", false, true, false, false, false);

        $this->info("Queues ready: {$queue}, {$queue}.retry, {$queue}.dlq");

        if ($this->option('debug')) {
            $channel->queue_declare('debug.inventory', false, true, false, false, false, new AMQPTable(['x-max-length' => 1000]));
            $channel->queue_bind('debug.inventory', $exchange, 'inventory.#');
            $this->info('Debug queue "debug.inventory" keeps the last 1000 inventory.* events.');
        }

        $mq->close();

        return self::SUCCESS;
    }
}
