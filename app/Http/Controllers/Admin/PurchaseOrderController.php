<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function __construct(private PurchaseOrderService $orders) {}

    /** ?status=  ?supplier_id=  ?search=PO-2026-000001 */
    public function index(Request $request)
    {
        return PurchaseOrder::query()
            ->with(['supplier:id,name', 'warehouse:id,code,name'])
            ->withSum('items as quantity_ordered', 'quantity_ordered')
            ->withSum('items as quantity_received', 'quantity_received')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('supplier_id'), fn ($q, $s) => $q->where('supplier_id', $s))
            ->when($request->query('search'), fn ($q, $s) => $q->where('number', 'ilike', '%'.$s.'%'))
            ->latest('id')
            ->paginate(min((int) $request->query('per_page', 30), 100));
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        return $purchaseOrder->load(['supplier', 'warehouse', 'items', 'receipts.items']);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'expected_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...$this->itemRules(),
        ]);

        return response()->json($this->orders->create($data, $this->actor($request)), 201);
    }

    /** A draft pre-filled from the purchase-suggestions report. Optional variant_ids[] picks only some rows. */
    public function fromSuggestions(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'variant_ids' => ['nullable', 'array', 'max:500'],
            'variant_ids.*' => ['integer'],
            'expected_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json($this->orders->createFromSuggestions($data, $this->actor($request)), 201);
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        $data = $request->validate([
            'supplier_id' => ['sometimes', 'integer'],
            'warehouse_id' => ['sometimes', 'integer'],
            'expected_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...$this->itemRules(),
        ]);

        return $this->orders->update($purchaseOrder, $data);
    }

    /** ordered | cancelled | closed. Received / partially_received come from receiving goods. */
    public function changeStatus(Request $request, PurchaseOrder $purchaseOrder)
    {
        $data = $request->validate(['status' => ['required', 'in:ordered,cancelled,closed']]);

        return $this->orders->changeStatus($purchaseOrder, $data['status']);
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder)
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.variant_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        return response()->json(
            $this->orders->receive($purchaseOrder, $data['idempotency_key'], $data['lines'], $data['notes'] ?? null, $this->actor($request)),
            201
        );
    }

    private function itemRules(): array
    {
        return [
            'items' => ['sometimes', 'array', 'max:500'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    private function actor(Request $request): array
    {
        return ['type' => 'staff', 'id' => (int) $request->attributes->get('user_id')];
    }
}
