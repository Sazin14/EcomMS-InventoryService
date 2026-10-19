<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\StockService;
use Illuminate\Http\Request;

class VariantSyncController extends Controller
{
    /** ProductService calls this after creating or changing variants. */
    public function sync(Request $request, StockService $stock)
    {
        $data = $request->validate([
            'variants' => ['required', 'array', 'min:1', 'max:500'],
            'variants.*.variant_id' => ['required', 'integer'],
            'variants.*.sku' => ['required', 'string', 'max:100'],
            'variants.*.product_name' => ['nullable', 'string', 'max:255'],
            'variants.*.variant_label' => ['nullable', 'string', 'max:255'],
            // set in the create-product form; only applied when the key is present
            'variants.*.allow_preorder' => ['sometimes', 'boolean'],
        ]);

        return ['synced' => $stock->syncVariants($data['variants'])];
    }
}
