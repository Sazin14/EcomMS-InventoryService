<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\AvailabilityService;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    /** GET /api/availability?variant_ids[]=1&variant_ids[]=2 - public, shows status only (no exact stock). */
    public function index(Request $request, AvailabilityService $availability)
    {
        $data = $request->validate([
            'variant_ids' => ['required', 'array', 'min:1', 'max:100'],
            'variant_ids.*' => ['integer'],
        ]);

        return collect($availability->forVariants($data['variant_ids']))
            ->map(fn (array $r) => [
                'variant_id' => $r['variant_id'],
                'status' => $r['status'],   // in_stock | low_stock | preorder | out_of_stock
                'can_preorder' => $r['can_preorder'],
                'expected_restock_at' => $r['expected_restock_at'],
                'quantity_left' => $r['status'] === 'low_stock' ? $r['available'] : null,
            ])
            ->values();
    }
}
