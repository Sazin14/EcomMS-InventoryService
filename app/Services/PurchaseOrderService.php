<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReceipt;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Concerns\FailsValidation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Purchase orders are a record of what the manager bought manually from a supplier.
 * Status flow:  draft -> ordered -> partially_received -> received      (received/partial are set by receiving)
 *               draft|ordered -> cancelled        partially_received -> closed (supplier will not send the rest)
 */
class PurchaseOrderService
{
    use FailsValidation;

    /** Changes a manager may make by hand. Receiving moves the status on its own. */
    private const MANUAL = [
        PurchaseOrder::DRAFT => [PurchaseOrder::ORDERED, PurchaseOrder::CANCELLED],
        PurchaseOrder::ORDERED => [PurchaseOrder::CANCELLED],
        PurchaseOrder::PARTIALLY_RECEIVED => [PurchaseOrder::CLOSED],
    ];

    private const WITH = ['supplier:id,name', 'warehouse:id,code,name', 'items'];

    public function __construct(
        private StockService $stock,
        private ReportService $reports,
    ) {}

    /** @param array $data supplier_id, warehouse_id, expected_at?, notes?, items?: [['variant_id', 'quantity', 'unit_cost'?]] */
    public function create(array $data, array $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $actor) {
            $this->assertSupplier((int) $data['supplier_id']);
            $this->assertWarehouse((int) $data['warehouse_id']);

            $po = PurchaseOrder::create([
                'number' => 'TMP-'.Str::uuid(),   // replaced right below, once the id is known
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'status' => PurchaseOrder::DRAFT,
                'expected_at' => $data['expected_at'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by_id' => $actor['id'] ?? null,
            ]);

            $po->update(['number' => sprintf('PO-%s-%06d', now()->format('Y'), $po->id)]);

            $this->replaceItems($po, $data['items'] ?? []);

            return $po->load(self::WITH);
        });
    }

    /** Draft pre-filled from the "purchase suggestions" report (still editable before ordering). */
    public function createFromSuggestions(array $data, array $actor): PurchaseOrder
    {
        $rows = $this->reports->suggestions(['variant_ids' => $data['variant_ids'] ?? null])->get();

        if ($rows->isEmpty()) {
            $this->fail('variant_ids', 'There is nothing to buy for the selected variants.');
        }

        $data['items'] = $rows->map(fn ($r) => [
            'variant_id' => $r->variant_id,
            'quantity' => (int) $r->to_buy,
        ])->all();

        return $this->create($data, $actor);
    }

    /** Only drafts can be edited; sending "items" replaces the whole item list. */
    public function update(PurchaseOrder $purchaseOrder, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrder, $data) {
            $po = PurchaseOrder::whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();

            if ($po->status !== PurchaseOrder::DRAFT) {
                $this->fail('status', 'Only draft purchase orders can be edited.');
            }

            if (isset($data['supplier_id'])) {
                $this->assertSupplier((int) $data['supplier_id']);
            }

            if (isset($data['warehouse_id'])) {
                $this->assertWarehouse((int) $data['warehouse_id']);
            }

            $po->fill(Arr::only($data, ['supplier_id', 'warehouse_id', 'expected_at', 'notes']))->save();

            if (array_key_exists('items', $data)) {
                $this->replaceItems($po, $data['items'] ?? []);
            }

            return $po->load(self::WITH);
        });
    }

    public function changeStatus(PurchaseOrder $purchaseOrder, string $to): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrder, $to) {
            $po = PurchaseOrder::whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();

            if (! in_array($to, self::MANUAL[$po->status] ?? [], true)) {
                $this->fail('status', "A {$po->status} purchase order cannot be changed to {$to}.");
            }

            if ($to === PurchaseOrder::ORDERED) {
                if (! $po->items()->exists()) {
                    $this->fail('status', 'Add at least one item before marking it as ordered.');
                }

                $this->assertSupplier($po->supplier_id);
                $po->ordered_at = now();
            }

            if ($to === PurchaseOrder::CANCELLED) {
                $po->cancelled_at = now();
            }

            if ($to === PurchaseOrder::CLOSED) {
                $po->closed_at = now();   // the unreceived rest stops counting as "on order"
            }

            $po->status = $to;
            $po->save();

            return $po->load(self::WITH);
        });
    }

    /**
     * Goods arrived (possibly only part of the order).
     * Each line goes through StockService::receive: stock goes up, waiting pre-orders get it first, alerts update.
     * The same idempotency_key never counts twice.
     *
     * @param  array  $lines  [['variant_id' => 4, 'quantity' => 10], ...]
     */
    public function receive(PurchaseOrder $purchaseOrder, string $idempotencyKey, array $lines, ?string $notes, array $actor): PurchaseOrder
    {
        if ($existing = PurchaseReceipt::where('idempotency_key', $idempotencyKey)->first()) {
            return $this->replay($existing, $purchaseOrder);
        }

        try {
            return DB::transaction(function () use ($purchaseOrder, $idempotencyKey, $lines, $notes, $actor) {
                $po = PurchaseOrder::whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();

                if (! in_array($po->status, PurchaseOrder::OPEN, true)) {
                    $this->fail('status', "A {$po->status} purchase order cannot receive goods.");
                }

                $items = $po->items()->get()->keyBy('variant_id');

                $receipt = PurchaseReceipt::create([
                    'purchase_order_id' => $po->id,
                    'idempotency_key' => $idempotencyKey,
                    'notes' => $notes,
                    'received_by_type' => $actor['type'] ?? 'staff',
                    'received_by_id' => $actor['id'] ?? null,
                ]);

                $seen = [];

                foreach (array_values($lines) as $i => $line) {
                    $variantId = (int) $line['variant_id'];
                    $qty = (int) $line['quantity'];

                    if (isset($seen[$variantId])) {
                        $this->fail("lines.$i.variant_id", 'Variant listed twice.');
                    }

                    $seen[$variantId] = true;

                    $item = $items->get($variantId)
                        ?? $this->fail("lines.$i.variant_id", 'This variant is not on the purchase order.');

                    // Atomic guard: never receive more than was ordered.
                    $updated = PurchaseOrderItem::whereKey($item->id)
                        ->whereRaw('quantity_received + ? <= quantity_ordered', [$qty])
                        ->increment('quantity_received', $qty);

                    if ($updated !== 1) {
                        $left = $item->quantity_ordered - (int) PurchaseOrderItem::whereKey($item->id)->value('quantity_received');
                        $this->fail("lines.$i.quantity", "Only {$left} unit(s) are still expected for this variant.");
                    }

                    $receipt->items()->create([
                        'purchase_order_item_id' => $item->id,
                        'variant_id' => $variantId,
                        'sku' => $item->sku,
                        'quantity' => $qty,
                    ]);

                    $this->stock->receive([
                        'variant_id' => $variantId,
                        'warehouse_id' => $po->warehouse_id,
                        'quantity' => $qty,
                        'reference_type' => 'purchase_order',
                        'reference_id' => $po->number,
                        'reason' => "Received against {$po->number}",
                    ], $actor);
                }

                $complete = ! PurchaseOrderItem::where('purchase_order_id', $po->id)
                    ->whereColumn('quantity_received', '<', 'quantity_ordered')
                    ->exists();

                $po->status = $complete ? PurchaseOrder::RECEIVED : PurchaseOrder::PARTIALLY_RECEIVED;

                if ($complete) {
                    $po->received_at = now();
                }

                $po->save();

                return $po->load([...self::WITH, 'receipts.items']);
            });
        } catch (UniqueConstraintViolationException) {
            // two identical requests raced: the other one won
            return $this->replay(PurchaseReceipt::where('idempotency_key', $idempotencyKey)->firstOrFail(), $purchaseOrder);
        }
    }

    private function replay(PurchaseReceipt $receipt, PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        if ((int) $receipt->purchase_order_id !== (int) $purchaseOrder->id) {
            $this->fail('idempotency_key', 'This key was already used for another purchase order.');
        }

        return $purchaseOrder->fresh()->load([...self::WITH, 'receipts.items']);
    }

    private function replaceItems(PurchaseOrder $po, array $items): void
    {
        $po->items()->delete();

        $skus = InventoryItem::whereIn('variant_id', collect($items)->pluck('variant_id')->map(fn ($v) => (int) $v))
            ->pluck('sku', 'variant_id');

        $seen = [];

        foreach (array_values($items) as $i => $row) {
            $variantId = (int) $row['variant_id'];

            if (isset($seen[$variantId])) {
                $this->fail("items.$i.variant_id", 'Variant listed twice.');
            }

            $sku = $skus->get($variantId) ?? $this->fail("items.$i.variant_id", 'This variant is not registered in inventory.');
            $seen[$variantId] = true;

            $po->items()->create([
                'variant_id' => $variantId,
                'sku' => $sku,
                'quantity_ordered' => (int) $row['quantity'],
                'unit_cost' => $row['unit_cost'] ?? null,
            ]);
        }
    }

    private function assertSupplier(int $id): void
    {
        $supplier = Supplier::find($id) ?? $this->fail('supplier_id', 'Supplier not found.');

        if (! $supplier->is_active) {
            $this->fail('supplier_id', 'This supplier is inactive.');
        }
    }

    private function assertWarehouse(int $id): void
    {
        $warehouse = Warehouse::find($id);

        if (! $warehouse || ! $warehouse->is_active) {
            $this->fail('warehouse_id', 'Warehouse not found or inactive.');
        }
    }
}
