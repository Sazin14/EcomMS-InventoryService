<?php

namespace App\Services;

use App\Exceptions\StockMismatchException;
use App\Models\Reservation;
use App\Support\Outbox;
use App\Support\RabbitMq\EventHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OrderEventHandler implements EventHandler
{
    public function __construct(
        private ReservationService $reservations,
        private PreorderService $preorders,
        private ReturnService $returns,
    ) {}

    public function handle(array $envelope): void
    {
        $data = $envelope['data'] ?? [];
        $number = (string) ($data['order_number'] ?? '');

        if ($number === '') {
            return;
        }

        match ($envelope['type']) {
            'order.confirmed' => $this->confirmed($number),
            'order.cancelled' => $this->cancelled($number),
            'order.shipped' => $this->shipped($number),
            'return.received' => $this->returnReceived($number, $data),
            default => null,
        };
    }

    private function confirmed(string $number): void
    {
        $holds = Reservation::where('reference', $number)->get();
        $held = false;

        foreach ($holds as $hold) {
            if ($hold->status === Reservation::ACTIVE) {
                $this->attempt($number, 'order.confirmed', fn () => $this->reservations->confirm($hold->id));
                $held = true;
            } elseif (in_array($hold->status, [Reservation::CONFIRMED, Reservation::FULFILLED], true)) {
                $held = true;
            }
        }

        if (! $held) {
            $this->reject($number, 'order.confirmed', 'The stock hold for this order has expired or was released.');
        }
    }

    private function cancelled(string $number): void
    {
        $ids = Reservation::where('reference', $number)
            ->whereIn('status', [Reservation::ACTIVE, Reservation::CONFIRMED])
            ->pluck('id');

        foreach ($ids as $id) {
            $this->attempt($number, 'order.cancelled', fn () => $this->reservations->release($id));
        }

        $this->attempt($number, 'order.cancelled', fn () => $this->preorders->cancelAllFor($number));
    }

    private function shipped(string $number): void
    {
        $holds = Reservation::where('reference', $number)
            ->whereIn('status', [Reservation::ACTIVE, Reservation::CONFIRMED, Reservation::FULFILLED])
            ->get();

        if ($holds->isEmpty()) {
            $this->reject($number, 'order.shipped', 'No stock is held for this order (the hold expired or was released).');

            return;
        }

        foreach ($holds as $hold) {
            $this->attempt($number, 'order.shipped', function () use ($hold) {
                if ($hold->status === Reservation::ACTIVE) {
                    $this->reservations->confirm($hold->id);
                }

                $this->reservations->fulfil($hold->id);
            });
        }
    }

    private function returnReceived(string $number, array $data): void
    {
        $returnNumber = (string) ($data['return_number'] ?? 'unknown');
        $shipped = Reservation::with('items')->where('reference', $number)->where('status', Reservation::FULFILLED)->get();
        $byHold = [];

        foreach ((array) ($data['items'] ?? []) as $line) {
            $variantId = (int) ($line['variant_id'] ?? 0);
            $hold = $shipped->first(fn (Reservation $r) => $r->items->contains('variant_id', $variantId));

            if (! $hold) {
                $this->reject($number, 'return.received', "Variant {$variantId} was not shipped for this order.");

                continue;
            }

            $byHold[$hold->id][] = [
                'variant_id' => $variantId,
                'quantity' => (int) $line['quantity'],
                'condition' => $line['condition'] ?? 'damaged',
                'warehouse_id' => null,
            ];
        }

        foreach ($byHold as $holdId => $items) {
            $this->attempt($number, 'return.received', fn () => $this->returns->receive(
                $holdId,
                "return:{$returnNumber}:{$holdId}",
                $items,
                $data['reason'] ?? 'Customer return',
                ['type' => 'order_service', 'id' => null]
            ));
        }
    }

    private function attempt(string $number, string $event, callable $step): void
    {
        try {
            $step();
        } catch (ValidationException $e) {
            $this->reject($number, $event, collect($e->errors())->flatten()->first() ?? $e->getMessage());
        } catch (StockMismatchException $e) {
            $this->reject($number, $event, $e->getMessage());
        }
    }

    private function reject(string $number, string $event, string $reason): void
    {
        Log::warning("Inventory refused {$event} for {$number}: {$reason}");

        Outbox::record('inventory.order.rejected', [
            'order_number' => $number,
            'event' => $event,
            'reason' => $reason,
        ]);
    }
}
