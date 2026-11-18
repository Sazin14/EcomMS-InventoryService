<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\StockMismatchException;
use App\Models\InventoryItem;
use App\Models\Preorder;
use App\Models\Reservation;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Services\Concerns\FailsValidation;
use App\Support\Outbox;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReservationService
{
    use FailsValidation;

    public function __construct(
        private StockAllocator $allocator,
        private AvailabilityService $availability,
        private AlertService $alerts,
        private PreorderService $preorders,
        private FlashSaleGate $flashGate,
    ) {}

    /**
     * @param array $items
     * @param int|null $customerId
     * @throws InsufficientStockException
     */
    public function reserve(string $reference, array $items, string $idempotencyKey, ?int $ttlMinutes = null, ?int $customerId = null): Reservation
    {
        if ($existing = Reservation::where('idempotency_key', $idempotencyKey)->first()) {
            if (! in_array($existing->status, [Reservation::RELEASED, Reservation::EXPIRED], true)) {
                return $existing->load('items');
            }

            $existing->update(['idempotency_key' => mb_substr($idempotencyKey, 0, 120).'#retired:'.$existing->id]);
        }

        $lines = $this->mergeLines($items);
        $skus = InventoryItem::whereIn('variant_id', array_keys($lines))->pluck('sku', 'variant_id');

        if ($skus->count() !== count($lines)) {
            $this->fail('items', 'One or more variants are unknown to inventory.');
        }

        $ttl = $ttlMinutes ?? (int) config('inventory.reservation_ttl_minutes', 15);

        $taken = $this->takeFromFlashGate($lines, $customerId);

        try {
            return DB::transaction(function () use ($reference, $lines, $skus, $idempotencyKey, $ttl, $customerId) {
                $reservation = Reservation::create([
                    'reference' => $reference,
                    'idempotency_key' => $idempotencyKey,
                    'customer_id' => $customerId,
                    'status' => Reservation::ACTIVE,
                    'expires_at' => now()->addMinutes($ttl),
                ]);

                $shortages = [];

                foreach ($lines as $variantId => $qty) {
                    $split = $this->allocator->reserve($variantId, $qty);

                    if ($split === null) {
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

                    foreach ($split as $warehouseId => $part) {
                        $reservation->items()->create([
                            'variant_id' => $variantId,
                            'sku' => $skus[$variantId],
                            'warehouse_id' => $warehouseId,
                            'quantity' => $part,
                        ]);
                    }
                }

                if ($shortages) {
                    throw new InsufficientStockException($shortages);
                }

                foreach (array_keys($lines) as $variantId) {
                    $this->alerts->checkStock($variantId);
                }

                return $reservation->load('items');
            });
        } catch (UniqueConstraintViolationException) {
            $this->giveBackToFlashGate($taken, $customerId);

            return Reservation::where('idempotency_key', $idempotencyKey)->firstOrFail()->load('items');
        } catch (Throwable $e) {
            $this->giveBackToFlashGate($taken, $customerId);

            throw $e;
        }
    }

    /**
     * @param array<int,int> $lines
     * @return array<int,int>
     */
    private function takeFromFlashGate(array $lines, ?int $customerId): array
    {
        $flash = InventoryItem::whereIn('variant_id', array_keys($lines))->where('flash_sale', true)->get()->keyBy('variant_id');
        $taken = [];

        foreach ($lines as $variantId => $qty) {
            $item = $flash->get($variantId);

            if (! $item) {
                continue;
            }

            $result = $this->flashGate->take($variantId, $qty, $customerId, $item->flash_limit_per_customer);

            if ($result === FlashSaleGate::OK) {
                $taken[$variantId] = $qty;

                continue;
            }

            if ($result === FlashSaleGate::UNKNOWN) {
                continue;
            }

            $this->giveBackToFlashGate($taken, $customerId);

            if ($result === FlashSaleGate::LIMIT) {
                $this->fail('items', "Limit reached: at most {$item->flash_limit_per_customer} unit(s) of variant {$variantId} per customer in this sale.");
            }

            throw new InsufficientStockException([[
                'variant_id' => $variantId,
                'requested' => $qty,
                'available' => 0,
                'can_preorder' => false,
                'expected_restock_at' => null,
            ]]);
        }

        return $taken;
    }

    /** @param array<int,int> $taken */
    private function giveBackToFlashGate(array $taken, ?int $customerId): void
    {
        foreach ($taken as $variantId => $qty) {
            $this->flashGate->giveBack($variantId, $qty, $customerId);
        }
    }

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

            $r->update(['status' => Reservation::CONFIRMED, 'expires_at' => null]);

            return $r->load('items');
        });
    }

    public function release(int $id): Reservation
    {
        return DB::transaction(fn () => $this->releaseHold($id, Reservation::RELEASED));
    }

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
                    throw new StockMismatchException("Stock mismatch while fulfilling variant {$item->variant_id}.");
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

            Preorder::where('reservation_id', $r->id)->where('status', 'allocated')->update(['status' => 'fulfilled']);

            return $r->load('items');
        });
    }

    public function expireStale(): int
    {
        $ids = Reservation::where('status', Reservation::ACTIVE)
            ->where('expires_at', '<', now())
            ->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id) {
                $r = $this->releaseHold($id, Reservation::EXPIRED);

                if ($r->status === Reservation::EXPIRED && $r->wasChanged('status')) {
                    Outbox::record('inventory.reservation.expired', [
                        'reference' => $r->reference,
                        'reservation_id' => $r->id,
                    ]);
                }
            });
        }

        return $ids->count();
    }

    private function releaseHold(int $id, string $newStatus): Reservation
    {
        $r = Reservation::whereKey($id)->lockForUpdate()->firstOrFail();

        if ($newStatus === Reservation::EXPIRED && $r->status !== Reservation::ACTIVE) {
            return $r;
        }

        if ($r->status === Reservation::FULFILLED) {
            $this->fail('reservation', 'Already shipped; handle it as a return instead.');
        }

        if (! in_array($r->status, [Reservation::ACTIVE, Reservation::CONFIRMED], true)) {
            return $r->load('items');
        }

        foreach ($r->items as $item) {
            $updated = StockLevel::where('variant_id', $item->variant_id)
                ->where('warehouse_id', $item->warehouse_id)
                ->where('reserved', '>=', $item->quantity)
                ->decrement('reserved', $item->quantity);

            if ($updated !== 1) {
                throw new StockMismatchException("Reserved stock mismatch for variant {$item->variant_id}.");
            }
        }

        $r->update(['status' => $newStatus]);

        $flashIds = InventoryItem::whereIn('variant_id', $r->items->pluck('variant_id'))->where('flash_sale', true)->pluck('variant_id')->all();

        foreach ($r->items->whereIn('variant_id', $flashIds) as $item) {
            $this->flashGate->giveBack($item->variant_id, $item->quantity, $r->customer_id);
        }

        foreach ($r->items->pluck('variant_id')->unique() as $variantId) {
            $this->preorders->allocate($variantId);
        }

        return $r->load('items');
    }

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
