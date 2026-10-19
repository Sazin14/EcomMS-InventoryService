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

/**
 * Goods coming back after shipping: customer refused at the door (COD), could not be reached,
 * wrong or defective item, or a return after delivery. Inventory only counts units;
 * the money side (COD collected / refund) belongs to the Order / Payment service.
 */
class ReturnService
{
    use FailsValidation;

    public function __construct(
        private StockService $stock,
        private AlertService $alerts,
    ) {}

    /**
     * @param  array  $items  [['variant_id' => 1, 'quantity' => 1, 'condition' => 'resellable|damaged', 'warehouse_id' => null], ...]
     * @param  array  $actor  ['type' => 'staff', 'id' => 5]
     */
    public function receive(int $reservationId, string $idempotencyKey, array $items, ?string $reason, array $actor): StockReturn
    {
        if ($existing = StockReturn::where('idempotency_key', $idempotencyKey)->first()) {
            return $existing->load('items');   // repeated request: nothing is counted twice
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

                    $item = $reservation->items->firstWhere('variant_id', $variantId)
                        ?? $this->fail("items.$i.variant_id", 'This variant is not part of the order.');

                    // Atomic guard: total returned can never exceed what was shipped.
                    $updated = ReservationItem::whereKey($item->id)
                        ->whereRaw('returned_quantity + ? <= quantity', [$qty])
                        ->increment('returned_quantity', $qty);

                    if ($updated !== 1) {
                        $left = $item->quantity - (int) ReservationItem::whereKey($item->id)->value('returned_quantity');
                        $this->fail("items.$i.quantity", "Only {$left} unit(s) of this variant can still be returned.");
                    }

                    $warehouseId = (int) ($row['warehouse_id'] ?? $item->warehouse_id);

                    $return->items()->create([
                        'variant_id' => $variantId,
                        'sku' => $item->sku,
                        'warehouse_id' => $warehouseId,
                        'quantity' => $qty,
                        'condition' => $row['condition'],
                    ]);

                    if ($row['condition'] === StockReturnItem::RESELLABLE) {
                        // Back on the shelf: +on_hand, a "return" movement, and waiting pre-orders get first pick.
                        $this->stock->receive([
                            'variant_id' => $variantId,
                            'warehouse_id' => $warehouseId,
                            'quantity' => $qty,
                            'reference_type' => 'order',
                            'reference_id' => $reservation->reference,
                            'reason' => $reason ?? 'Customer return',
                        ], $actor, StockMovement::TYPE_RETURN);
                    } else {
                        $this->alerts->raise(
                            'return_damaged', $variantId, $item->sku, $qty,
                            "{$qty} x {$item->sku} came back damaged from {$reservation->reference}; not added to sellable stock."
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
