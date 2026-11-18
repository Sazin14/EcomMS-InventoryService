<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\Preorder;
use App\Models\Reservation;
use App\Support\Outbox;
use App\Services\Concerns\FailsValidation;
use Illuminate\Support\Facades\DB;

class PreorderService
{
    use FailsValidation;

    public function __construct(
        private StockAllocator $allocator,
        private AvailabilityService $availability,
        private AlertService $alerts,
    ) {}

    public function create(string $reference, int $variantId, int $quantity): Preorder
    {
        $existing = Preorder::where('reference', $reference)->where('variant_id', $variantId)->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($reference, $variantId, $quantity) {
            $item = InventoryItem::where('variant_id', $variantId)->lockForUpdate()->first()
                ?? $this->fail('variant_id', 'Unknown variant.');

            if (! $item->allow_preorder) {
                $this->fail('variant_id', 'Pre-order is not enabled for this variant.');
            }

            if ($this->availability->availableFor($variantId) >= $quantity) {
                $this->fail('quantity', 'Enough stock is available: reserve it instead of pre-ordering.');
            }

            $pending = (int) Preorder::where('variant_id', $variantId)->where('status', 'pending')->sum('quantity');

            if ($item->preorder_limit !== null && $pending + $quantity > $item->preorder_limit) {
                $this->fail('quantity', 'The pre-order limit for this variant has been reached.');
            }

            $preorder = Preorder::create([
                'reference' => $reference,
                'variant_id' => $variantId,
                'quantity' => $quantity,
                'status' => 'pending',
            ]);

            $name = $item->product_name ?: $item->sku;
            $this->alerts->raise('preorder_created', $variantId, $item->sku, $quantity, "New pre-order: {$quantity} x {$name} ({$item->sku}) for {$reference}.");

            return $preorder;
        });
    }

    public function allocate(int $variantId): int
    {
        $sku = InventoryItem::where('variant_id', $variantId)->value('sku');
        $count = 0;

        $pending = Preorder::where('variant_id', $variantId)
            ->where('status', 'pending')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($pending as $preorder) {
            $split = $this->allocator->reserve($variantId, $preorder->quantity);

            if ($split === null) {
                break;
            }

            $reservation = Reservation::create([
                'reference' => $preorder->reference,
                'idempotency_key' => "preorder:{$preorder->id}",
                'status' => Reservation::CONFIRMED,
                'expires_at' => null,
            ]);

            foreach ($split as $warehouseId => $part) {
                $reservation->items()->create([
                    'variant_id' => $variantId,
                    'sku' => $sku,
                    'warehouse_id' => $warehouseId,
                    'quantity' => $part,
                ]);
            }

            $preorder->update([
                'status' => 'allocated',
                'reservation_id' => $reservation->id,
                'allocated_at' => now(),
            ]);

            $this->alerts->raise(
                'preorder_allocated', $variantId, $sku, $preorder->quantity,
                "Stock is now held for pre-order {$preorder->reference} ({$preorder->quantity} x {$sku})."
            );

            Outbox::record('inventory.preorder.allocated', [
                'reference' => $preorder->reference,
                'variant_id' => $variantId,
                'sku' => $sku,
                'quantity' => $preorder->quantity,
                'reservation_id' => $reservation->id,
            ]);

            $count++;
        }

        return $count;
    }

    public function cancelAllFor(string $reference): int
    {
        $variantIds = Preorder::where('reference', $reference)
            ->whereIn('status', ['pending', 'allocated'])
            ->pluck('variant_id');

        foreach ($variantIds as $variantId) {
            $this->cancel($reference, (int) $variantId);
        }

        return $variantIds->count();
    }

    public function cancel(string $reference, int $variantId): Preorder
    {
        return DB::transaction(function () use ($reference, $variantId) {
            $preorder = Preorder::where('reference', $reference)->where('variant_id', $variantId)->lockForUpdate()->firstOrFail();

            if (in_array($preorder->status, ['cancelled', 'fulfilled'], true)) {
                return $preorder;
            }

            if ($preorder->status === 'allocated' && $preorder->reservation_id
                && Reservation::whereKey($preorder->reservation_id)->value('status') === Reservation::FULFILLED) {
                $preorder->update(['status' => 'fulfilled']);

                return $preorder;
            }

            if ($preorder->status === 'allocated' && $preorder->reservation_id) {
                app(ReservationService::class)->release($preorder->reservation_id);
            }

            $preorder->update(['status' => 'cancelled']);

            return $preorder;
        });
    }
}
