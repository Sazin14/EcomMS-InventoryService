<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Services\AvailabilityService;
use App\Services\StockService;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function __construct(private StockService $stock) {}

    /** Dashboard table. ?search=  ?filter=low|out  ?per_page= */
    public function index(Request $request)
    {
        $available = '(select coalesce(sum(on_hand - reserved), 0) from stock_levels where stock_levels.variant_id = inventory_items.variant_id)';

        return InventoryItem::query()
            ->withSum('levels as on_hand', 'on_hand')
            ->withSum('levels as reserved', 'reserved')
            ->when($request->query('search'), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('sku', 'ilike', '%'.$s.'%')->orWhere('product_name', 'ilike', '%'.$s.'%')
            ))
            ->when($request->query('filter') === 'out', fn ($q) => $q->whereRaw("{$available} <= 0"))
            ->when($request->query('filter') === 'low', fn ($q) => $q->whereHas('levels', fn ($l) => $l
                ->where('reorder_level', '>', 0)
                ->whereRaw('on_hand - reserved > 0 and on_hand - reserved <= reorder_level')))
            ->orderBy('product_name')->orderBy('sku')
            ->paginate(min((int) $request->query('per_page', 25), 100));
    }

    public function show(int $variant, AvailabilityService $availability)
    {
        $item = InventoryItem::where('variant_id', $variant)->firstOrFail();

        return [
            'item' => $item,
            'availability' => $availability->forVariants([$variant])[$variant],
            'levels' => StockLevel::with('warehouse:id,code,name,is_active')->where('variant_id', $variant)->get(),
            'recent_movements' => StockMovement::where('variant_id', $variant)->latest('id')->limit(50)->get(),
        ];
    }

    public function movements(Request $request)
    {
        return StockMovement::query()
            ->when($request->query('variant_id'), fn ($q, $v) => $q->where('variant_id', $v))
            ->when($request->query('warehouse_id'), fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->query('reference_id'), fn ($q, $v) => $q->where('reference_id', $v))
            ->latest('id')
            ->paginate(min((int) $request->query('per_page', 50), 200));
    }

    public function receive(Request $request)
    {
        $data = $request->validate([
            'variant_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reference_type' => ['nullable', 'string', 'max:100'],
            'reference_id' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json($this->stock->receive($data, $this->actor($request)), 201);
    }

    public function adjust(Request $request)
    {
        $data = $request->validate([
            'variant_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'quantity_change' => ['required', 'integer', 'between:-1000000,1000000'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return $this->stock->adjust($data, $this->actor($request));
    }

    public function reorderLevel(Request $request, int $variant, int $warehouse)
    {
        $data = $request->validate(['reorder_level' => ['required', 'integer', 'min:0', 'max:1000000']]);

        return $this->stock->setReorderLevel($variant, $warehouse, $data['reorder_level']);
    }

    /**
     * Pre-order limit and expected restock date. The on/off switch (allow_preorder) is set when the
     * product is created or edited in ProductService, not here.
     */
    public function policy(Request $request, int $variant)
    {
        $data = $request->validate([
            'preorder_limit' => ['nullable', 'integer', 'min:0'],
            'expected_restock_at' => ['nullable', 'date'],
        ]);

        $item = InventoryItem::where('variant_id', $variant)->firstOrFail();
        $item->update($data);

        return $item->fresh();
    }

    private function actor(Request $request): array
    {
        return ['type' => 'staff', 'id' => (int) $request->attributes->get('user_id')];
    }
}
