<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\StockService;
use Illuminate\Http\Request;

class VariantSyncController extends Controller
{
    public function sync(Request $request, StockService $stock)
    {
        $data = $request->validate([
            'force' => ['sometimes', 'boolean'],
            'variants' => ['required', 'array', 'min:1', 'max:500'],
            'variants.*.variant_id' => ['required', 'integer'],
            'variants.*.sku' => ['required', 'string', 'max:100'],
            'variants.*.product_name' => ['nullable', 'string', 'max:255'],
            'variants.*.variant_label' => ['nullable', 'string', 'max:255'],
            'variants.*.allow_preorder' => ['sometimes', 'boolean'],
        ]);

        return $stock->syncVariants($data['variants'], (bool) ($data['force'] ?? false));
    }
}
