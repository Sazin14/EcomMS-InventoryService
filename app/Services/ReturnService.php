<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ReservationItem;
use App\Models\StockMovement;
use App\Models\StockReturn;
use App\Models\StockReturnItem;
use App\Services\Concerns\FailsValidation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ReturnService
{
    use FailsValidation;

    public function __construct(
        private StockService $stock,
        private AlertService $alerts,
    ) {}

    /**
     * @param array $items
     * @param array $actor
     */
    public function receive(int $reservationId, string $idempotencyKey, array $items, ?string $reason, array $actor): StockReturn
    {
        if ($existing = StockReturn::where('idempotency_key', $idempotencyKey)->first()) {
            return $existing->load('items');
        }

        try {
            return DB::transaction(function () use ($reservationId, $idempotencyKey, $items, $reason, $actor) {
                $reservation = Reservation::whereKey($reservationId)->lockForUpdate()->firstOrFail();

                if ($reservation->status !== Reservation::FULFILLED) {
                    $this->fail('reservation', "Only shipped orders can be returned (this one is {$reservation->status}).");
                }

                $return = StockReturn::create([
                    'reservation_id' => $reservation->id,
                    'reference' => $reservation->reference,
                    'idempotency_key' => $idempotencyKey,
                    'reason' => $reason,
                    'received_by_type' => $actor['type'] ?? 'system',
                    'received_by_id' => $actor['id'] ?? null,
                ]);

                $seen = [];

                foreach (array_values($items) as $i => $row) {
                    $variantId = (int) $row['variant_id'];
                    $qty = (int) $row['quantity'];

                    if (isset($seen[$variantId])) {
                        $this->fail("items.$i.variant_id", 'Variant listed twice.');
                    }

                    $seen[$variantId] = true;

                    $lines = $reservation->items->where('variant_id', $variantId)->sortBy('id')->values();

                    if ($lines->isEmpty()) {
                        $this->fail("items.$i.variant_id", 'This variant is not part of the order.');
                    }

                    $sku = $lines->first()->sku;
                    $left = $qty;

                    foreach ($lines as $item) {
                        $room = (int) $item->quantity - (int) ReservationItem::whereKey($item->id)->lockForUpdate()->value('returned_quantity');
                        $part = min($left, $room);

                        if ($part <= 0) {
                            continue;
                        }

                        $updated = ReservationItem::whereKey($item->id)
                            ->whereRaw('returned_quantity + ? <= quantity', [$part])
                            ->increment('returned_quantity', $part);

                        if ($updated !== 1) {
                            continue;
                        }

                        $warehouseId = (int) ($row['warehouse_id'] ?? $item->warehouse_id);

                        $return->items()->create([
                            'variant_id' => $variantId,
                            'sku' => $item->sku,
                            'warehouse_id' => $warehouseId,
                            'quantity' => $part,
                            'condition' => $row['condition'],
                        ]);

                        if ($row['condition'] === StockReturnItem::RESELLABLE) {
                            $this->stock->receive([
                                'variant_id' => $variantId,
                                'warehouse_id' => $warehouseId,
                                'quantity' => $part,
                                'reference_type' => 'order',
                                'reference_id' => $reservation->reference,
                                'reason' => $reason ?? 'Customer return',
                            ], $actor, StockMovement::TYPE_RETURN);
                        }

                        $left -= $part;

                        if ($left === 0) {
                            break;
                        }
                    }

                    if ($left > 0) {
                        $this->fail("items.$i.quantity", 'Only '.($qty - $left).' unit(s) of this variant can still be returned.');
                    }

                    if ($row['condition'] !== StockReturnItem::RESELLABLE) {
                        $this->alerts->raise(
                            'return_damaged', $variantId, $sku, $qty,
                            "{$qty} x {$sku} came back damaged from {$reservation->reference}; not added to sellable stock."
                        );
                    }
                }

                return $return->load('items');
            });
        } catch (UniqueConstraintViolationException) {
            return StockReturn::where('idempotency_key', $idempotencyKey)->firstOrFail()->load('items');
        }
    }
}
