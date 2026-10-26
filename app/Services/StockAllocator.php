<?php

namespace App\Services;

use App\Models\StockLevel;

/** The one place that holds stock for an order line, atomically. */
class StockAllocator
{
    /**
     * Reserves $qty of a variant in ONE warehouse (default warehouse first, then the one with most free stock).
     * Returns the warehouse id, or null when no single warehouse can cover the quantity.
     *
     * The conditional UPDATE is the anti-overselling guard: the check ("enough free stock?") and the
     * change happen in one statement, so two simultaneous buyers can never both take the last unit.
     * Must run inside a transaction.
     */
    public function reserveLine(int $variantId, int $qty): ?int
    {
        $warehouseIds = StockLevel::query()
            ->join('warehouses as w', 'w.id', '=', 'stock_levels.warehouse_id')
            ->where('w.is_active', true)
            ->where('stock_levels.variant_id', $variantId)
            ->orderByDesc('w.is_default')
            ->orderByRaw('(stock_levels.on_hand - stock_levels.reserved) desc')
            ->pluck('stock_levels.warehouse_id');

        foreach ($warehouseIds as $warehouseId) {
            $updated = StockLevel::where('variant_id', $variantId)
                ->where('warehouse_id', $warehouseId)
                ->whereRaw('on_hand - reserved >= ?', [$qty])
                ->increment('reserved', $qty);

            if ($updated === 1) {
                return (int) $warehouseId;
            }
        }

        return null;
    }
}
