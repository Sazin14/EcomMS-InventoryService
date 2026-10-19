<?php

namespace App\Services;

use App\Models\InventoryItem;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Query builders for the four inventory reports. Controllers paginate them or stream them as CSV.
 * Stock numbers only count ACTIVE warehouses.
 */
class ReportService
{
    // "available" and "to buy" written out, because SQL cannot filter on a column alias of the same SELECT.
    private const AVAILABLE = 'greatest(on_hand - reserved, 0)';
    private const TO_BUY = 'greatest(pending_preorders + reorder_total - greatest(on_hand - reserved, 0) - on_order, 0)';

    /** 1. Low stock: a warehouse is at or under its own reorder level (includes warehouses that hit zero). */
    public function lowStock(array $f): Builder
    {
        return DB::table('stock_levels as sl')
            ->join('warehouses as w', 'w.id', '=', 'sl.warehouse_id')
            ->join('inventory_items as i', 'i.variant_id', '=', 'sl.variant_id')
            ->where('w.is_active', true)
            ->whereNull('i.discontinued_at')
            ->where('sl.reorder_level', '>', 0)
            ->whereRaw('sl.on_hand - sl.reserved <= sl.reorder_level')
            ->when($f['warehouse_id'] ?? null, fn ($q, $v) => $q->where('sl.warehouse_id', $v))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(
                fn ($w) => $w->where('i.sku', 'ilike', '%'.$s.'%')->orWhere('i.product_name', 'ilike', '%'.$s.'%')
            ))
            ->selectRaw("i.variant_id, i.sku, i.product_name, i.variant_label, w.code as warehouse,
                sl.on_hand, sl.reserved, (sl.on_hand - sl.reserved) as available, sl.reorder_level,
                case when sl.on_hand - sl.reserved <= 0 then 'empty' else 'low' end as state")
            ->orderByRaw('(sl.on_hand - sl.reserved) asc')
            ->orderBy('i.sku');
    }

    /** 2. Out of stock: nothing available in any active warehouse (variants never stocked are included). */
    public function outOfStock(array $f): Builder
    {
        return $this->position()
            ->whereNull('discontinued_at')
            ->whereRaw(self::AVAILABLE.' <= 0')
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(
                fn ($w) => $w->where('sku', 'ilike', '%'.$s.'%')->orWhere('product_name', 'ilike', '%'.$s.'%')
            ))
            ->orderBy('product_name')
            ->orderBy('sku');
    }

    /** 3. Pre-order demand: pending pre-orders per variant, and how much is still not covered. */
    public function preorderDemand(array $f): Builder
    {
        return $this->position()
            ->where('pending_preorders', '>', 0)
            ->selectRaw('greatest(pending_preorders - '.self::AVAILABLE.' - on_order, 0) as uncovered')
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(
                fn ($w) => $w->where('sku', 'ilike', '%'.$s.'%')->orWhere('product_name', 'ilike', '%'.$s.'%')
            ))
            ->orderByDesc('pending_preorders')
            ->orderBy('sku');
    }

    /**
     * 4. Purchase suggestions (what to order from suppliers):
     *    to_buy = pending pre-orders + reorder level - available stock - already on order, never below 0.
     * Free stock first serves waiting pre-orders, then has to reach the reorder level.
     */
    public function suggestions(array $f = []): Builder
    {
        return $this->position()
            ->selectRaw(self::TO_BUY.' as to_buy')
            ->whereNull('discontinued_at')
            ->whereRaw(self::TO_BUY.' > 0')
            ->when($f['variant_ids'] ?? null, fn ($q, $ids) => $q->whereIn('variant_id', array_map('intval', (array) $ids)))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(
                fn ($w) => $w->where('sku', 'ilike', '%'.$s.'%')->orWhere('product_name', 'ilike', '%'.$s.'%')
            ))
            ->orderByRaw(self::TO_BUY.' desc')
            ->orderBy('sku');
    }

    /** One row per variant with every number the reports need. */
    private function position(): Builder
    {
        $levels = fn (string $column) => "coalesce((select sum(sl.{$column})
            from stock_levels sl join warehouses w on w.id = sl.warehouse_id
            where sl.variant_id = inventory_items.variant_id and w.is_active), 0)";

        $pending = fn (string $select) => "(select {$select} from preorders p
            where p.variant_id = inventory_items.variant_id and p.status = 'pending')";

        $base = InventoryItem::query()
            ->select([
                'inventory_items.variant_id', 'inventory_items.sku', 'inventory_items.product_name',
                'inventory_items.variant_label', 'inventory_items.allow_preorder',
                'inventory_items.preorder_limit', 'inventory_items.expected_restock_at', 'inventory_items.discontinued_at',
            ])
            ->selectRaw($levels('on_hand').' as on_hand')
            ->selectRaw($levels('reserved').' as reserved')
            ->selectRaw($levels('reorder_level').' as reorder_total')
            ->selectRaw('coalesce('.$pending('sum(p.quantity)').', 0) as pending_preorders')
            ->selectRaw($pending('count(*)').' as pending_orders')
            ->selectRaw($pending('min(p.created_at)').' as oldest_preorder_at')
            ->selectRaw("coalesce((select sum(i.quantity_ordered - i.quantity_received)
                from purchase_order_items i join purchase_orders o on o.id = i.purchase_order_id
                where i.variant_id = inventory_items.variant_id and o.status in ('ordered', 'partially_received')), 0) as on_order");

        return DB::query()
            ->fromSub($base, 'x')
            ->selectRaw('x.*, '.self::AVAILABLE.' as available');
    }
}
