<?php

namespace App\Services;

use App\Models\StockLevel;

class StockAllocator
{
    /** @return array<int,int>|null */
    public function reserve(int $variantId, int $qty): ?array
    {
        if ($warehouseId = $this->reserveLine($variantId, $qty)) {
            return [$warehouseId => $qty];
        }

        $levels = StockLevel::query()
            ->join('warehouses as w', 'w.id', '=', 'stock_levels.warehouse_id')
            ->where('w.is_active', true)
            ->where('stock_levels.variant_id', $variantId)
            ->orderBy('stock_levels.id')
            ->lockForUpdate()
            ->get(['stock_levels.*', 'w.is_default']);

        $free = fn (StockLevel $l) => max(0, (int) $l->on_hand - (int) $l->reserved);

        if ($levels->sum($free) < $qty) {
            return null;
        }

        $taken = [];
        $left = $qty;

        foreach ($levels->sortByDesc(fn (StockLevel $l) => [(int) (bool) $l->is_default, $free($l)]) as $level) {
            $take = min($left, $free($level));

            if ($take <= 0) {
                continue;
            }

            $updated = StockLevel::whereKey($level->id)
                ->whereRaw('on_hand - reserved >= ?', [$take])
                ->increment('reserved', $take);

            if ($updated !== 1) {
                return null;
            }

            $taken[(int) $level->warehouse_id] = $take;
            $left -= $take;

            if ($left === 0) {
                break;
            }
        }

        return $left === 0 ? $taken : null;
    }

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
