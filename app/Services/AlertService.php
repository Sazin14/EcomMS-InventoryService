<?php

namespace App\Services;

use App\Models\InventoryAlert;
use App\Models\InventoryItem;
use App\Models\StockLevel;
use App\Support\Outbox;

/** Writes the notifications shown on the inventory dashboard, and publishes low/out-of-stock events. */
class AlertService
{
    /** Returns false when an identical unread alert already exists (dedupe) and nothing was created. */
    public function raise(string $type, int $variantId, ?string $sku, ?int $quantity, string $message, bool $dedupe = false): bool
    {
        // Do not stack identical unread alerts (e.g. "out of stock" on every failed reservation).
        if ($dedupe && InventoryAlert::where('variant_id', $variantId)->where('type', $type)->whereNull('read_at')->exists()) {
            return false;
        }

        InventoryAlert::create([
            'type' => $type,
            'variant_id' => $variantId,
            'sku' => $sku,
            'quantity' => $quantity,
            'message' => $message,
        ]);

        return true;
    }

    /**
     * Call after anything that lowers available stock.
     *  - out_of_stock: the variant has nothing available in any active warehouse.
     *  - low_stock: a warehouse is at or under its own reorder level.
     * A new alert also becomes an event (inventory.stock.out / inventory.stock.low) in the outbox.
     */
    public function checkStock(int $variantId): void
    {
        $item = InventoryItem::where('variant_id', $variantId)->first();

        if (! $item) {
            return;
        }

        $levels = StockLevel::with('warehouse:id,code,is_active')
            ->where('variant_id', $variantId)
            ->get()
            ->filter(fn (StockLevel $level) => $level->warehouse?->is_active);

        $name = $item->product_name ? "{$item->product_name} ({$item->sku})" : $item->sku;
        $total = $levels->sum(fn (StockLevel $level) => $level->available);

        if ($total <= 0) {
            if ($this->raise('out_of_stock', $variantId, $item->sku, 0, "{$name} is out of stock.", true)) {
                Outbox::record('inventory.stock.out', ['variant_id' => $variantId, 'sku' => $item->sku]);
            }

            return;
        }

        $low = $levels->first(fn (StockLevel $level) => $level->isLowStock());

        if ($low && $this->raise(
            'low_stock', $variantId, $item->sku, $low->available,
            "{$name} is low in {$low->warehouse->code}: {$low->available} left (reorder level {$low->reorder_level}).",
            true
        )) {
            Outbox::record('inventory.stock.low', [
                'variant_id' => $variantId,
                'sku' => $item->sku,
                'warehouse' => $low->warehouse->code,
                'available' => $low->available,
                'reorder_level' => $low->reorder_level,
            ]);
        }
    }
}
