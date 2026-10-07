<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryAlert;
use App\Models\InventoryItem;
use App\Models\Preorder;
use Illuminate\Http\Request;

/** Inventory dashboard: notifications and the pre-order report. */
class DashboardController extends Controller
{
    /** ?unread=1  ?type=low_stock|out_of_stock|preorder_created|preorder_allocated */
    public function alerts(Request $request)
    {
        return InventoryAlert::query()
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->latest('id')
            ->paginate(min((int) $request->query('per_page', 30), 100));
    }

    public function markRead(InventoryAlert $alert)
    {
        $alert->update(['read_at' => now()]);

        return $alert;
    }

    public function markAllRead()
    {
        return ['marked' => InventoryAlert::whereNull('read_at')->update(['read_at' => now()])];
    }

    /** Individual pre-orders. ?status=pending|allocated|cancelled  ?variant_id= */
    public function preorders(Request $request)
    {
        return Preorder::with('item:variant_id,sku,product_name,variant_label')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('variant_id'), fn ($q, $v) => $q->where('variant_id', $v))
            ->latest('id')
            ->paginate(min((int) $request->query('per_page', 30), 100));
    }

    /** The pre-order report: demand per variant, so the manager knows what to order from suppliers. */
    public function preorderSummary()
    {
        $pending = fn ($q) => $q->where('status', 'pending');

        return InventoryItem::query()
            ->whereHas('preorders', $pending)
            ->withSum(['preorders as pending_quantity' => $pending], 'quantity')
            ->withCount(['preorders as pending_orders' => $pending])
            ->withSum('levels as on_hand', 'on_hand')
            ->withSum('levels as reserved', 'reserved')
            ->orderByDesc('pending_quantity')
            ->paginate(30);
    }
}
