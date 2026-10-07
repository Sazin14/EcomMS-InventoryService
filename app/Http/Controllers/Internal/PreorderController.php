<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Preorder;
use App\Services\PreorderService;
use Illuminate\Http\Request;

class PreorderController extends Controller
{
    public function __construct(private PreorderService $preorders) {}

    /** The Order service polls this to learn when a pre-order got its stock (status = allocated). */
    public function index(Request $request)
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:100']]);

        return Preorder::where('reference', $data['reference'])->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:100'],
            'variant_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        return response()->json(
            $this->preorders->create($data['reference'], $data['variant_id'], $data['quantity']),
            201
        );
    }

    public function destroy(string $reference, int $variantId)
    {
        return $this->preorders->cancel($reference, $variantId);
    }
}
