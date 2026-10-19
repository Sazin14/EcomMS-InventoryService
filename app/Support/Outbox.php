<?php

namespace App\Support;

use App\Models\OutboxMessage;
use Illuminate\Support\Str;

/**
 * The outbox pattern: an event is saved in the SAME database transaction as the change that caused it,
 * and a separate worker (php artisan outbox:publish) sends it to RabbitMQ afterwards.
 * Result: either both the change and its event exist, or neither does.
 *
 *   DB::transaction(function () {
 *       $variant->update([...]);
 *       Outbox::record('catalog.variant.upserted', [...]);   // rolled back together with the update
 *   });
 */
class Outbox
{
    public static function record(string $routingKey, array $data): void
    {
        $id = (string) Str::uuid();
        $at = now();

        $envelope = [
            'id' => $id,                                        // consumers use it to ignore repeats
            'type' => $routingKey,
            'version' => 1,
            'occurred_at' => $at->toIso8601ZuluString('microsecond'),
            'producer' => config('rabbitmq.producer'),
            'data' => $data,
        ];

        OutboxMessage::create([
            'message_id' => $id,
            'routing_key' => $routingKey,
            'payload' => json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'occurred_at' => $at,
            'available_at' => $at,
        ]);
    }
}
