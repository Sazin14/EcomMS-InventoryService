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
    'producer' => env('RABBITMQ_PRODUCER', 'inventory-service'),

    // ====================================================================================================
    // SHARED SECTION: keep everything from here to the END marker IDENTICAL in every service.
    // Queues are declared by every service when it connects; RabbitMQ refuses a declaration whose
    // settings differ from the existing queue, so a mismatch here breaks publishing / consuming.
    // ====================================================================================================
    'retry_delay_ms' => 30000,   // wait before a failed message is tried again
    'max_attempts' => 5,         // after this many failures the message is parked in <queue>.dlq
    'audit_queue' => 'events.audit',   // keeps a copy of EVERY event for 7 days (so no event is ever unroutable)

    // Every consumer queue of the system: queue name => routing keys it listens to.
    // Declared by publishers AND consumers, so an event is never dropped because the consumer was deployed later.
    'queues' => [
        // InventoryService
        'inventory.catalog' => ['catalog.variant.*'],
        'inventory.orders' => ['order.confirmed', 'order.cancelled', 'order.shipped', 'return.received'],
        // OrderService
        'order.inventory' => ['inventory.preorder.allocated', 'inventory.reservation.expired', 'inventory.order.rejected'],
        // NotificationService
        'notification.order' => ['order.#', 'payment.#', 'return.#', 'refund.#'],
        'notification.inventory' => ['inventory.stock.low', 'inventory.stock.out'],
    ],
    // ============================================ END shared section ====================================

    // Queues THIS service consumes: queue name => handler class (implements App\Support\RabbitMq\EventHandler).
    // php artisan rabbitmq:consume <queue>
    'consumers' => [
        'inventory.catalog' => App\Services\CatalogEventHandler::class,
        'inventory.orders' => App\Services\OrderEventHandler::class,
    ],

    // Outbox rows that still fail after this many attempts are set aside (php artisan outbox:failed shows / re-queues them).
    'outbox_max_attempts' => (int) env('OUTBOX_MAX_ATTEMPTS', 25),
];
