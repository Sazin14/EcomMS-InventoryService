<?php

return [
    'host' => env('RABBITMQ_HOST', '127.0.0.1'),
    'port' => (int) env('RABBITMQ_PORT', 5672),
    'user' => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
    'vhost' => env('RABBITMQ_VHOST', 'ecom'),
    'ssl' => (bool) env('RABBITMQ_SSL', false),   // true in production (amqps, port 5671)

    // Events of every service travel through ONE topic exchange.
    'exchange' => 'ecom.events',
    // Failed messages wait here (a queue with a TTL) and then go back to the main queue.
    'retry_exchange' => 'ecom.retry',

    // Written into every event envelope ("producer").
    'producer' => env('RABBITMQ_PRODUCER', 'unknown-service'),

    'retry_delay_ms' => 30000,   // wait before a failed message is tried again
    'max_attempts' => 5,         // after this many failures the message is parked in <queue>.dlq
];
