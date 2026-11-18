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

    /**
     * Sends up to $limit waiting events, oldest first. Returns how many were published.
     *
     * A message counts as published only when the broker CONFIRMED it and did not RETURN it as unroutable
     * (mandatory flag). A failed row is retried later with a growing delay (1s, 2s, 4s ... 5 min) and, after
     * outbox_max_attempts, set aside with failed_at (see: php artisan outbox:failed).
     *
     * Order: rows are normally sent oldest first, but a row that has to wait for its retry is overtaken by
     * newer ones. Consumers must not depend on strict order (InventoryService ignores stale events by timestamp).
     */
    public function publishBatch(int $limit = 100): int
    {
        $published = 0;

        DB::transaction(function () use ($limit, &$published) {
            // "skip locked": a second relay instance would take other rows instead of waiting.
            $rows = OutboxMessage::whereNull('published_at')
                ->whereNull('failed_at')
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

            $returned = false;
            $channel->set_return_listener(function () use (&$returned) {
                $returned = true;   // called by wait_for_pending_acks_returns() when the message was unroutable
            });

            foreach ($rows as $row) {
                $returned = false;

                try {
                    $channel->basic_publish(
                        new AMQPMessage($row->payload, [
                            'content_type' => 'application/json',
                            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                            'message_id' => $row->message_id,
                            'type' => $row->routing_key,
                        ]),
                        config('rabbitmq.exchange'),
                        $row->routing_key,
                        true   // mandatory: an unroutable message is returned instead of silently dropped
                    );

                    $channel->wait_for_pending_acks_returns(5);

                    if ($returned) {
                        throw new UnroutableMessageException("No queue is bound to [{$row->routing_key}]; the broker returned the event.");
                    }

                    $row->update(['published_at' => now(), 'attempts' => $row->attempts + 1, 'last_error' => null]);
                    $published++;
                } catch (UnroutableMessageException $e) {
                    // Only this event has a problem; the broker itself is fine, so carry on with the next one.
                    $this->markFailed($row, $e);
                } catch (Throwable $e) {
                    // Broker or network problem: stop this batch (everything else would fail too).
                    $this->markFailed($row, $e);
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

    private function markFailed(OutboxMessage $row, Throwable $e): void
    {
        $attempts = $row->attempts + 1;

        $update = [
            'attempts' => $attempts,
            'last_error' => Str::limit($e->getMessage(), 500),
            'available_at' => now()->addSeconds(min(300, 2 ** min($row->attempts, 8))),
        ];

        if ($attempts >= (int) config('rabbitmq.outbox_max_attempts', 25)) {
            $update['failed_at'] = now();   // give up automatically; a human re-queues it with outbox:failed --retry
        }

        $row->update($update);

        report($e);
    }
}
