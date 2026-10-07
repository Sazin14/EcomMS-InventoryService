<?php

namespace App\Services;

use App\Models\InventoryAlert;
use App\Models\InventoryItem;
use App\Models\StockLevel;

/** Writes the notifications shown on the inventory dashboard. */
class AlertService
{
    public function raise(string $type, int $variantId, ?string $sku, ?int $quantity, string $message, bool $dedupe = false): void
    {
        // Do not stack identical unread alerts (e.g. "out of stock" on every failed reservation).
        if ($dedupe && InventoryAlert::where('variant_id', $variantId)->where('type', $type)->whereNull('read_at')->exists()) {
            return;
        }

        InventoryAlert::create([
            'type' => $type,
            'variant_id' => $variantId,
            'sku' => $sku,
            'quantity' => $quantity,
            'message' => $message,
        ]);
    }

    /**
     * Call after anything that lowers available stock.
     *  - out_of_stock: the variant has nothing available in any active warehouse.
     *  - low_stock: a warehouse is at or under its own reorder level.
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
            $this->raise('out_of_stock', $variantId, $item->sku, 0, "{$name} is out of stock.", true);

            return;
        }

        $low = $levels->first(fn (StockLevel $level) => $level->isLowStock());

        if ($low) {
            $this->raise(
                'low_stock', $variantId, $item->sku, $low->available,
                "{$name} is low in {$low->warehouse->code}: {$low->available} left (reorder level {$low->reorder_level}).",
                true
            );
        }
    }
}
