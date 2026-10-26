<?php

namespace App\Support\RabbitMq;

use App\Models\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class OutboxPublisher
{
    public function __construct(private RabbitMqConnection $mq) {}

    /** Sends up to $limit waiting events, oldest first. Returns how many were published. */
    public function publishBatch(int $limit = 100): int
    {
        $published = 0;

        DB::transaction(function () use ($limit, &$published) {
            // "skip locked": a second relay instance would take other rows instead of waiting.
            $rows = OutboxMessage::whereNull('published_at')
                ->where('available_at', '<=', now())
                ->orderBy('id')
                ->limit($limit)
                ->lock('for update skip locked')
                ->get();

            if ($rows->isEmpty()) {
                return;
            }

            $channel = $this->mq->channel();
            $channel->confirm_select();   // the broker confirms every message it has safely accepted

            foreach ($rows as $row) {
                try {
                    $channel->basic_publish(
                        new AMQPMessage($row->payload, [
                            'content_type' => 'application/json',
                            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                            'message_id' => $row->message_id,
                            'type' => $row->routing_key,
                        ]),
                        config('rabbitmq.exchange'),
                        $row->routing_key
                    );

                    $channel->wait_for_pending_acks(5);

                    $row->update(['published_at' => now(), 'attempts' => $row->attempts + 1, 'last_error' => null]);
                    $published++;
                } catch (Throwable $e) {
                    // Retry later with a growing delay (1s, 2s, 4s ... max 5 min), and stop here so that
                    // later events are never sent before this older one.
                    $row->update([
                        'attempts' => $row->attempts + 1,
                        'last_error' => Str::limit($e->getMessage(), 500),
                        'available_at' => now()->addSeconds(min(300, 2 ** min($row->attempts, 8))),
                    ]);

                    report($e);
                    $this->mq->close();

                    break;
                }
            }
        });

        return $published;
    }

    public function close(): void
    {
        $this->mq->close();
    }
}
