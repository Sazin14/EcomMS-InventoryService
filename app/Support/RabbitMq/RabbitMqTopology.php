<?php

namespace App\Support\RabbitMq;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * The queues of the system. Declaring is idempotent, so every service can (and does) call it on connect.
 *
 *   <queue>        main queue, bound to the events it listens to; a rejected message is dead-lettered to ecom.retry
 *   <queue>.retry  holds a failed message for retry_delay_ms, then sends it back to <queue>
 *   <queue>.dlq    messages that failed max_attempts times wait here for a human (rabbitmq:dlq)
 *   events.audit   copy of every event (7 days, 100k messages): makes every event routable and gives a replay source
 */
class RabbitMqTopology
{
    public static function declareAll(AMQPChannel $channel): void
    {
        foreach ((array) config('rabbitmq.queues', []) as $queue => $bindingKeys) {
            self::declareConsumerQueue($channel, (string) $queue, (array) $bindingKeys);
        }

        self::declareAuditQueue($channel);
    }

    /** @param  array<int,string>|string  $bindingKeys  routing keys (patterns) this queue listens to */
    public static function declareConsumerQueue(AMQPChannel $channel, string $queue, array|string $bindingKeys): void
    {
        $exchange = config('rabbitmq.exchange');
        $retryExchange = config('rabbitmq.retry_exchange');

        $channel->queue_declare($queue, false, true, false, false, false, new AMQPTable([
            'x-dead-letter-exchange' => $retryExchange,
            'x-dead-letter-routing-key' => $queue,
        ]));
        foreach ((array) $bindingKeys as $bindingKey) {
            $channel->queue_bind($queue, $exchange, (string) $bindingKey);
        }

        $channel->queue_declare("{$queue}.retry", false, true, false, false, false, new AMQPTable([
            'x-message-ttl' => (int) config('rabbitmq.retry_delay_ms'),
            'x-dead-letter-exchange' => '',               // "" = default exchange, routes by queue name
            'x-dead-letter-routing-key' => $queue,
        ]));
        $channel->queue_bind("{$queue}.retry", $retryExchange, $queue);

        $channel->queue_declare("{$queue}.dlq", false, true, false, false, false);
    }

    public static function declareAuditQueue(AMQPChannel $channel): void
    {
        $queue = (string) config('rabbitmq.audit_queue');

        $channel->queue_declare($queue, false, true, false, false, false, new AMQPTable([
            'x-message-ttl' => 7 * 24 * 3600 * 1000,
            'x-max-length' => 100000,
        ]));
        $channel->queue_bind($queue, config('rabbitmq.exchange'), '#');
    }
}
