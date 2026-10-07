<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\StockReturn;
use App\Services\ReturnService;
use Illuminate\Http\Request;

/**
 * Used by warehouse staff (dashboard, after inspecting the parcel) and by the Order service.
 */
class ReturnController extends Controller
{
    public function __construct(private ReturnService $returns) {}

    /** Find the shipped order a parcel belongs to: ?reference=ORD-1&status=fulfilled */
    public function reservations(Request $request)
    {
        return Reservation::with('items')
            ->when($request->query('reference'), fn ($q, $r) => $q->where('reference', $r))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest('id')
            ->paginate(min((int) $request->query('per_page', 30), 100));
    }

    public function store(Request $request, Reservation $reservation)
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:150'],
            'reason' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'items.*.condition' => ['required', 'in:resellable,damaged'],
            'items.*.warehouse_id' => ['nullable', 'integer'],
        ]);

        $actor = $request->attributes->has('user_id')
            ? ['type' => 'staff', 'id' => (int) $request->attributes->get('user_id')]
            : ['type' => 'order_service', 'id' => null];

        $return = $this->returns->receive(
            $reservation->id, $data['idempotency_key'], $data['items'], $data['reason'] ?? null, $actor
        );

        return response()->json($return, 201);
    }

    /** Return report. ?reference= */
    public function index(Request $request)
    {
        return StockReturn::with('items')
            ->when($request->query('reference'), fn ($q, $r) => $q->where('reference', $r))
            ->latest('id')
            ->paginate(min((int) $request->query('per_page', 30), 100));
    }
}
