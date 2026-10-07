<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Concerns\FailsValidation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Changes on_hand. Every change writes a StockMovement in the same transaction.
 * $actor = ['type' => 'staff', 'id' => 5]   (or 'system' / 'order_service' with a null id)
 */
class StockService
{
    use FailsValidation;

    public function __construct(
        private PreorderService $preorders,
        private AlertService $alerts,
    ) {}

    /**
     * ProductService pushes its variants here. Safe to call again and again.
     * allow_preorder is owned by the product form (ProductService); the limit and restock date
     * stay with the inventory manager and are never touched by a sync.
     */
    public function syncVariants(array $variants): int
    {
        DB::transaction(function () use ($variants) {
            foreach ($variants as $v) {
                InventoryItem::updateOrCreate(
                    ['variant_id' => $v['variant_id']],
                    Arr::only($v, ['sku', 'product_name', 'variant_label', 'allow_preorder'])   // absent keys are left untouched
                );

                // keep the sku copies on stock rows in step with ProductService
                StockLevel::where('variant_id', $v['variant_id'])->update(['sku' => $v['sku']]);
            }
        });

        return count($variants);
    }

    /** Goods arrived (supplier receiving) or came back from a customer ($type = 'return'). */
    public function receive(array $d, array $actor, string $type = StockMovement::TYPE_RECEIPT): StockLevel
    {
        return DB::transaction(function () use ($d, $actor) {
            $level = $this->lockLevel((int) $d['variant_id'], (int) $d['warehouse_id']);
            $qty = (int) $d['quantity'];

            $level->on_hand += $qty;
            $level->save();

            $this->record($level, $type, $qty, $d['reference_type'] ?? 'purchase_order', $d['reference_id'] ?? null, $d['reason'] ?? null, $actor);

            // New stock goes first to customers waiting on a pre-order.
            $this->preorders->allocate($level->variant_id);

            return $level->fresh();
        });
    }

    /** Correction: damaged, lost, counting error. Signed, never zero, reason required. */
    public function adjust(array $d, array $actor): StockLevel
    {
        $change = (int) $d['quantity_change'];

        if ($change === 0) {
            $this->fail('quantity_change', 'Quantity change cannot be zero.');
        }

        return DB::transaction(function () use ($d, $change, $actor) {
            $level = $this->lockLevel((int) $d['variant_id'], (int) $d['warehouse_id']);

            if ($level->on_hand + $change < $level->reserved) {
                $this->fail('quantity_change', "Cannot go below the {$level->reserved} units already reserved.");
            }

            $level->on_hand += $change;
            $level->save();

            $this->record($level, StockMovement::TYPE_ADJUSTMENT, $change, 'manual_adjustment', null, $d['reason'], $actor);

            if ($change > 0) {
                $this->preorders->allocate($level->variant_id);
            }

            $this->alerts->checkStock($level->variant_id);

            return $level->fresh();
        });
    }

    public function setReorderLevel(int $variantId, int $warehouseId, int $reorderLevel): StockLevel
    {
        return DB::transaction(function () use ($variantId, $warehouseId, $reorderLevel) {
            $level = $this->lockLevel($variantId, $warehouseId);
            $level->update(['reorder_level' => $reorderLevel]);
            $this->alerts->checkStock($variantId);

            return $level->fresh();
        });
    }

    /** Finds (or creates) the stock row for variant + warehouse and locks it for this transaction. */
    private function lockLevel(int $variantId, int $warehouseId): StockLevel
    {
        $item = InventoryItem::where('variant_id', $variantId)->first()
            ?? $this->fail('variant_id', 'This variant is not registered in inventory yet.');

        $warehouse = Warehouse::find($warehouseId);

        if (! $warehouse || ! $warehouse->is_active) {
            $this->fail('warehouse_id', 'Warehouse not found or inactive.');
        }

        $level = StockLevel::firstOrCreate(
            ['variant_id' => $variantId, 'warehouse_id' => $warehouseId],
            ['sku' => $item->sku]
        );

        return StockLevel::whereKey($level->id)->lockForUpdate()->first();
    }

    private function record(StockLevel $level, string $type, int $qty, ?string $refType, ?string $refId, ?string $reason, array $actor): void
    {
        StockMovement::create([
            'variant_id' => $level->variant_id,
            'sku' => $level->sku,
            'warehouse_id' => $level->warehouse_id,
            'type' => $type,
            'quantity' => $qty,
            'reference_type' => $refType,
            'reference_id' => $refId,
            'reason' => $reason,
            'created_by_type' => $actor['type'] ?? 'system',
            'created_by_id' => $actor['id'] ?? null,
        ]);
    }
}
