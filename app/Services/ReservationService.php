<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\InventoryItem;
use App\Models\Reservation;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Services\Concerns\FailsValidation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Called by the Order service:
 *   reserve   -> checkout started   (stock held, expires)
 *   confirm   -> payment succeeded  (held until shipped)
 *   release   -> cancelled / payment failed
 *   fulfil    -> shipped            (stock leaves the warehouse)
 *   expireStale (scheduled)         -> unpaid reservations free their stock
 * Every call is safe to repeat.
 */
class ReservationService
{
    use FailsValidation;

    public function __construct(
        private StockAllocator $allocator,
        private AvailabilityService $availability,
        private AlertService $alerts,
        private PreorderService $preorders,
    ) {}

    /**
     * @param  array  $items  [['variant_id' => 1, 'quantity' => 2], ...]
     * @throws InsufficientStockException (409) listing every variant that could not be covered
     */
    public function reserve(string $reference, array $items, string $idempotencyKey, ?int $ttlMinutes = null): Reservation
    {
        // Same key again (client retry) -> same reservation, no second hold.
        if ($existing = Reservation::where('idempotency_key', $idempotencyKey)->first()) {
            return $existing->load('items');
        }

        $lines = $this->mergeLines($items);
        $skus = InventoryItem::whereIn('variant_id', array_keys($lines))->pluck('sku', 'variant_id');

        if ($skus->count() !== count($lines)) {
            $this->fail('items', 'One or more variants are unknown to inventory.');
        }

        $ttl = $ttlMinutes ?? (int) config('inventory.reservation_ttl_minutes', 15);

        try {
            return DB::transaction(function () use ($reference, $lines, $skus, $idempotencyKey, $ttl) {
                $reservation = Reservation::create([
                    'reference' => $reference,
                    'idempotency_key' => $idempotencyKey,
                    'status' => Reservation::ACTIVE,
                    'expires_at' => now()->addMinutes($ttl),
                ]);

                $shortages = [];

                foreach ($lines as $variantId => $qty) {
                    $warehouseId = $this->allocator->reserveLine($variantId, $qty);

                    if ($warehouseId === null) {
                        $info = $this->availability->forVariants([$variantId])[$variantId];
                        $shortages[] = [
                            'variant_id' => $variantId,
                            'requested' => $qty,
                            'available' => $info['available'],
                            'can_preorder' => $info['can_preorder'],
                            'expected_restock_at' => $info['expected_restock_at'],
                        ];

                        continue;
                    }

                    $reservation->items()->create([
                        'variant_id' => $variantId,
                        'sku' => $skus[$variantId],
                        'warehouse_id' => $warehouseId,
                        'quantity' => $qty,
                    ]);
                }

                if ($shortages) {
                    throw new InsufficientStockException($shortages);   // rolls everything back
                }

                foreach (array_keys($lines) as $variantId) {
                    $this->alerts->checkStock($variantId);
                }

                return $reservation->load('items');
            });
        } catch (UniqueConstraintViolationException) {
            // Two identical requests raced: the other one won, return its reservation.
            return Reservation::where('idempotency_key', $idempotencyKey)->firstOrFail()->load('items');
        }
    }

    /** Payment succeeded: keep the stock until it ships. */
    public function confirm(int $id): Reservation
    {
        return DB::transaction(function () use ($id) {
            $r = Reservation::whereKey($id)->lockForUpdate()->firstOrFail();

            if (in_array($r->status, [Reservation::CONFIRMED, Reservation::FULFILLED], true)) {
                return $r->load('items');
            }

            if ($r->status !== Reservation::ACTIVE) {
                $this->fail('reservation', "Reservation is {$r->status}; cannot confirm.");
            }

            // Even if expires_at just passed: the stock is still held (the expiry job has not run),
            // so the customer who already paid keeps it.
            $r->update(['status' => Reservation::CONFIRMED, 'expires_at' => null]);

            return $r->load('items');
        });
    }

    /** Cancelled or payment failed: give the stock back. */
    public function release(int $id): Reservation
    {
        return DB::transaction(fn () => $this->releaseHold($id, Reservation::RELEASED));
    }

    /** Shipped: stock leaves the warehouse. */
    public function fulfil(int $id): Reservation
    {
        return DB::transaction(function () use ($id) {
            $r = Reservation::whereKey($id)->lockForUpdate()->firstOrFail();

            if ($r->status === Reservation::FULFILLED) {
                return $r->load('items');
            }

            if ($r->status !== Reservation::CONFIRMED) {
                $this->fail('reservation', "Only confirmed reservations can be fulfilled (this one is {$r->status}).");
            }

            foreach ($r->items as $item) {
                $updated = StockLevel::where('variant_id', $item->variant_id)
                    ->where('warehouse_id', $item->warehouse_id)
                    ->where('reserved', '>=', $item->quantity)
                    ->where('on_hand', '>=', $item->quantity)
                    ->update([
                        'on_hand' => DB::raw('on_hand - '.(int) $item->quantity),
                        'reserved' => DB::raw('reserved - '.(int) $item->quantity),
                    ]);

                if ($updated !== 1) {
                    throw new \RuntimeException("Stock mismatch while fulfilling variant {$item->variant_id}.");
                }

                StockMovement::create([
                    'variant_id' => $item->variant_id,
                    'sku' => $item->sku,
                    'warehouse_id' => $item->warehouse_id,
                    'type' => StockMovement::TYPE_SALE,
                    'quantity' => -$item->quantity,
                    'reference_type' => 'order',
                    'reference_id' => $r->reference,
                    'created_by_type' => 'order_service',
                ]);

                $this->alerts->checkStock($item->variant_id);
            }

            $r->update(['status' => Reservation::FULFILLED]);

            return $r->load('items');
        });
    }

    /** Scheduled every minute: unpaid reservations past expires_at free their stock. */
    public function expireStale(): int
    {
        $ids = Reservation::where('status', Reservation::ACTIVE)
            ->where('expires_at', '<', now())
            ->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(fn () => $this->releaseHold($id, Reservation::EXPIRED));
        }

        return $ids->count();
    }

    private function releaseHold(int $id, string $newStatus): Reservation
    {
        $r = Reservation::whereKey($id)->lockForUpdate()->firstOrFail();

        if ($newStatus === Reservation::EXPIRED && $r->status !== Reservation::ACTIVE) {
            return $r;   // paid in the meantime: never expire it
        }

        if ($r->status === Reservation::FULFILLED) {
            $this->fail('reservation', 'Already shipped; handle it as a return instead.');
        }

        if (! in_array($r->status, [Reservation::ACTIVE, Reservation::CONFIRMED], true)) {
            return $r->load('items');   // already released / expired: nothing to do
        }

        foreach ($r->items as $item) {
            $updated = StockLevel::where('variant_id', $item->variant_id)
                ->where('warehouse_id', $item->warehouse_id)
                ->where('reserved', '>=', $item->quantity)
                ->decrement('reserved', $item->quantity);

            if ($updated !== 1) {
                throw new \RuntimeException("Reserved stock mismatch for variant {$item->variant_id}.");
            }
        }

        $r->update(['status' => $newStatus]);

        // Freed stock goes to customers waiting on a pre-order.
        foreach ($r->items->pluck('variant_id')->unique() as $variantId) {
            $this->preorders->allocate($variantId);
        }

        return $r->load('items');
    }

    /** [['variant_id' => 1, 'quantity' => 1], ['variant_id' => 1, 'quantity' => 2]] -> [1 => 3] */
    private function mergeLines(array $items): array
    {
        $lines = [];

        foreach ($items as $item) {
            $variantId = (int) $item['variant_id'];
            $lines[$variantId] = ($lines[$variantId] ?? 0) + (int) $item['quantity'];
        }

        return $lines;
    }
}
