<?php

namespace App\Support\RabbitMq;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPSSLConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/** One connection + channel, opened on first use and reopened if it dropped. Call close() when done. */
class RabbitMqConnection
{
    private ?AbstractConnection $connection = null;

    private ?AMQPChannel $channel = null;

    public function channel(): AMQPChannel
    {
        if ($this->channel !== null && $this->connection?->isConnected() && $this->channel->is_open()) {
            return $this->channel;
        }

        $this->close();

        $c = config('rabbitmq');

        $this->connection = $c['ssl']
            ? new AMQPSSLConnection(
                $c['host'], $c['port'], $c['user'], $c['password'], $c['vhost'],
                ['verify_peer' => true],
                ['connection_timeout' => 5, 'read_write_timeout' => 130, 'keepalive' => true, 'heartbeat' => 60]
            )
            : new AMQPStreamConnection(
                $c['host'], $c['port'], $c['user'], $c['password'], $c['vhost'],
                false, 'AMQPLAIN', null, 'en_US', 5.0, 130.0, null, true, 60
            );

        $this->channel = $this->connection->channel();

        // Declaring an existing exchange with the same settings does nothing, so this is safe every time.
        $this->channel->exchange_declare($c['exchange'], 'topic', false, true, false);
        $this->channel->exchange_declare($c['retry_exchange'], 'direct', false, true, false);

        return $this->channel;
    }

    public function close(): void
    {
        try {
            $this->channel?->close();
            $this->connection?->close();
        } catch (\Throwable) {
            // already gone
        }

        $this->channel = null;
        $this->connection = null;
    }
}
