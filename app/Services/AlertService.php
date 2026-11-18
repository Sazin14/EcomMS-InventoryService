<?php

namespace App\Services;

use App\Models\InventoryAlert;
use App\Models\InventoryItem;
use App\Models\StockLevel;
use App\Support\Outbox;

class AlertService
{
    public function raise(string $type, int $variantId, ?string $sku, ?int $quantity, string $message, bool $dedupe = false): bool
    {
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
