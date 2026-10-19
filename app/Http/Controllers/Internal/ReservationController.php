<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\ReservationService;
use Illuminate\Http\Request;

/** Order service endpoints (protected by the internal key). */
class ReservationController extends Controller
{
    public function __construct(private ReservationService $reservations) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:100'],
            'idempotency_key' => ['required', 'string', 'max:150'],
            'ttl_minutes' => ['nullable', 'integer', 'min:1', 'max:4320'],   // COD: e.g. 1440 (24h) while staff confirm by phone
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        $reservation = $this->reservations->reserve(
            $data['reference'], $data['items'], $data['idempotency_key'], $data['ttl_minutes'] ?? null
        );

        return response()->json($reservation, 201);
    }

    public function show(Reservation $reservation)
    {
        return $reservation->load('items');
    }

    public function confirm(Reservation $reservation)
    {
        return $this->reservations->confirm($reservation->id);
    }

    public function release(Reservation $reservation)
    {
        return $this->reservations->release($reservation->id);
    }

    public function fulfil(Reservation $reservation)
    {
        return $this->reservations->fulfil($reservation->id);
    }
}
