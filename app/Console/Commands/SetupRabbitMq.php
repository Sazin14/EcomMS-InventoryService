<?php

namespace App\Console\Commands;

use App\Support\RabbitMq\RabbitMqConnection;
use Illuminate\Console\Command;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Creates the exchanges and queues (config/rabbitmq.php 'queues'). Not strictly needed any more:
 * both the publisher and rabbitmq:consume declare them when they connect. Handy to run once at deploy time
 * and to create the optional debug queue.
 */
class SetupRabbitMq extends Command
{
    protected $signature = 'rabbitmq:setup {--debug : also create a queue that keeps a copy of every inventory.* event, to look at in the management UI}';

    protected $description = 'Create the RabbitMQ exchanges and queues InventoryService uses';

    public function handle(RabbitMqConnection $mq): int
    {
        $channel = $mq->channel();   // declares the exchanges, every queue of config('rabbitmq.queues') and the audit queue

        foreach (array_keys((array) config('rabbitmq.queues')) as $queue) {
            $this->info("Queues ready: {$queue}, {$queue}.retry, {$queue}.dlq");
        }

        $this->info('Audit queue ready: '.config('rabbitmq.audit_queue'));

        if ($this->option('debug')) {
            $channel->queue_declare('debug.inventory', false, true, false, false, false, new AMQPTable(['x-max-length' => 1000]));
            $channel->queue_bind('debug.inventory', config('rabbitmq.exchange'), 'inventory.#');
            $this->info('Debug queue "debug.inventory" keeps the last 1000 inventory.* events.');
        }

        $mq->close();

        return self::SUCCESS;
    }
}
