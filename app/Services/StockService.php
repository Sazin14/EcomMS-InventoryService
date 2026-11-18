<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Concerns\FailsValidation;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class StockService
{
    use FailsValidation;

    public function __construct(
        private PreorderService $preorders,
        private AlertService $alerts,
    ) {}

    /** @return array{synced:int, skipped:int} */
    public function syncVariants(array $variants, bool $force = false): array
    {
        $synced = 0;
        $skipped = 0;

        DB::transaction(function () use ($variants, $force, &$synced, &$skipped) {
            foreach ($variants as $v) {
                $existing = InventoryItem::where('variant_id', $v['variant_id'])->lockForUpdate()->first();

                if (! $force && $existing?->catalog_event_at) {
                    $skipped++;

                    continue;
                }

                InventoryItem::updateOrCreate(
                    ['variant_id' => $v['variant_id']],
                    Arr::only($v, ['sku', 'product_name', 'variant_label', 'allow_preorder'])
                );

                StockLevel::where('variant_id', $v['variant_id'])->update(['sku' => $v['sku']]);
                $synced++;
            }
        });

        return ['synced' => $synced, 'skipped' => $skipped];
    }

    public function applyCatalogUpsert(array $d, CarbonInterface $at): void
    {
        $item = InventoryItem::where('variant_id', $d['variant_id'])->lockForUpdate()->first();

        if ($item?->catalog_event_at && $item->catalog_event_at->greaterThan($at)) {
            return;
        }

        $isNew = $item === null;

        InventoryItem::updateOrCreate(
            ['variant_id' => $d['variant_id']],
            [
                'sku' => $d['sku'],
                'product_name' => $d['product_name'] ?? null,
                'variant_label' => $d['variant_label'] ?? null,
                'allow_preorder' => (bool) ($d['allow_preorder'] ?? false),
                'catalog_event_at' => $at,
                'discontinued_at' => null,
            ]
        );

        StockLevel::where('variant_id', $d['variant_id'])->update(['sku' => $d['sku']]);

        if ($isNew && (int) ($d['initial_stock'] ?? 0) > 0) {
            $this->receiveInitialStock((int) $d['variant_id'], (int) $d['initial_stock']);
        }
    }

    private function receiveInitialStock(int $variantId, int $quantity): void
    {
        $warehouse = Warehouse::where('is_default', true)->where('is_active', true)->first();

        if (! $warehouse) {
            Log::warning('Initial stock ignored: there is no active default warehouse.', ['variant_id' => $variantId, 'quantity' => $quantity]);

            return;
        }

        $this->receive([
            'variant_id' => $variantId,
            'warehouse_id' => $warehouse->id,
            'quantity' => $quantity,
            'reference_type' => 'product_creation',
            'reference_id' => (string) $variantId,
            'reason' => 'Opening stock entered when the product was created.',
        ], ['type' => 'system', 'id' => null]);
    }

    public function applyCatalogDelete(int $variantId, CarbonInterface $at, ?string $sku = null): void
    {
        $item = InventoryItem::where('variant_id', $variantId)->lockForUpdate()->first();

        if (! $item) {
            if ($sku !== null && $sku !== '') {
                InventoryItem::create([
                    'variant_id' => $variantId,
                    'sku' => $sku,
                    'allow_preorder' => false,
                    'discontinued_at' => now(),
                    'catalog_event_at' => $at,
                ]);
            }

            return;
        }

        if ($item->catalog_event_at && $item->catalog_event_at->greaterThan($at)) {
            return;
        }

        $item->update([
            'allow_preorder' => false,
            'discontinued_at' => now(),
            'catalog_event_at' => $at,
        ]);
    }

    public function receive(array $d, array $actor, string $type = StockMovement::TYPE_RECEIPT): StockLevel
    {
        return DB::transaction(function () use ($d, $actor, $type) {
            $level = $this->lockLevel((int) $d['variant_id'], (int) $d['warehouse_id']);
            $qty = (int) $d['quantity'];

            $level->on_hand += $qty;
            $level->save();

            $this->record($level, $type, $qty, $d['reference_type'] ?? 'purchase_order', $d['reference_id'] ?? null, $d['reason'] ?? null, $actor);

            $this->preorders->allocate($level->variant_id);

            return $level->fresh();
        });
    }

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

    public function transfer(array $d, array $actor): array
    {
        $variantId = (int) $d['variant_id'];
        $from = (int) $d['from_warehouse_id'];
        $to = (int) $d['to_warehouse_id'];
        $qty = (int) $d['quantity'];

        if ($from === $to) {
            $this->fail('to_warehouse_id', 'The source and destination warehouse must be different.');
        }

        return DB::transaction(function () use ($variantId, $from, $to, $qty, $d, $actor) {
            $ids = [$from, $to];
            sort($ids);

            $levels = [];

            foreach ($ids as $warehouseId) {
                $levels[$warehouseId] = $this->lockLevel($variantId, $warehouseId);
            }

            $source = $levels[$from];
            $target = $levels[$to];
            $free = $source->on_hand - $source->reserved;

            if ($free < $qty) {
                $this->fail('quantity', "Only {$free} unit(s) are free in the source warehouse (reserved stock cannot be moved).");
            }

            $source->on_hand -= $qty;
            $source->save();
            $target->on_hand += $qty;
            $target->save();

            $transferId = (string) Str::uuid();
            $reason = $d['reason'] ?? null;

            $this->record($source, StockMovement::TYPE_TRANSFER_OUT, -$qty, 'transfer', $transferId, $reason, $actor);
            $this->record($target, StockMovement::TYPE_TRANSFER_IN, $qty, 'transfer', $transferId, $reason, $actor);

            $this->alerts->checkStock($variantId);

            return ['transfer_id' => $transferId, 'from' => $source->fresh(), 'to' => $target->fresh()];
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
