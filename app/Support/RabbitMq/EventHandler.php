<?php

namespace App\Support\RabbitMq;

/** Implemented by the class that processes the events of one queue (see "consumers" in config/rabbitmq.php). */
interface EventHandler
{
    /** @param array $envelope the decoded event: id, type, version, occurred_at, producer, data */
    public function handle(array $envelope): void;
}
