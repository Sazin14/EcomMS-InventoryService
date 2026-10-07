<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\Preorder;
use App\Models\StockLevel;
use Illuminate\Support\Facades\DB;

/** Read-only: how much can be sold, across active warehouses. */
class AvailabilityService
{
    /**
     * @return array<int, array{variant_id:int, on_hand:int, reserved:int, available:int, status:string,
     *                          can_preorder:bool, expected_restock_at:?string}>
     */
    public function forVariants(array $variantIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $variantIds)));

        $items = InventoryItem::whereIn('variant_id', $ids)->get()->keyBy('variant_id');

        $totals = StockLevel::query()
            ->join('warehouses as w', 'w.id', '=', 'stock_levels.warehouse_id')
            ->where('w.is_active', true)
            ->whereIn('stock_levels.variant_id', $ids)
            ->groupBy('stock_levels.variant_id')
            ->selectRaw('stock_levels.variant_id,
                         sum(stock_levels.on_hand) as on_hand,
                         sum(stock_levels.reserved) as reserved,
                         sum(stock_levels.reorder_level) as reorder_level')
            ->get()
            ->keyBy('variant_id');

        $pending = Preorder::where('status', 'pending')
            ->whereIn('variant_id', $ids)
            ->groupBy('variant_id')
            ->selectRaw('variant_id, sum(quantity) as qty')
            ->pluck('qty', 'variant_id');

        $result = [];

        foreach ($ids as $id) {
            $item = $items->get($id);
            $row = $totals->get($id);
            $onHand = (int) ($row?->on_hand ?? 0);
            $reserved = (int) ($row?->reserved ?? 0);
            $reorder = (int) ($row?->reorder_level ?? 0);   // sum of the warehouses' reorder levels
            $available = max(0, $onHand - $reserved);
            $pendingQty = (int) $pending->get($id, 0);

            $canPreorder = $item !== null
                && $item->allow_preorder
                && ($item->preorder_limit === null || $pendingQty < $item->preorder_limit);

            $status = match (true) {
                $available > 0 && $reorder > 0 && $available <= $reorder => 'low_stock',
                $available > 0 => 'in_stock',
                $canPreorder => 'preorder',
                default => 'out_of_stock',
            };

            $result[$id] = [
                'variant_id' => $id,
                'on_hand' => $onHand,
                'reserved' => $reserved,
                'available' => $available,
                'status' => $status,
                'can_preorder' => $canPreorder,
                'expected_restock_at' => $item?->expected_restock_at?->toDateString(),
            ];
        }

        return $result;
    }

    public function availableFor(int $variantId): int
    {
        return max(0, (int) StockLevel::query()
            ->join('warehouses as w', 'w.id', '=', 'stock_levels.warehouse_id')
            ->where('w.is_active', true)
            ->where('stock_levels.variant_id', $variantId)
            ->sum(DB::raw('stock_levels.on_hand - stock_levels.reserved')));
    }
}
