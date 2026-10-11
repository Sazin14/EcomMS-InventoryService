<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Services\SupplierService;
use Illuminate\Http\Request;

/** Suppliers are plain contact records. Deactivate with PUT {"is_active": false}. */
class SupplierController extends Controller
{
    public function __construct(private SupplierService $suppliers) {}

    /** ?search=  ?active=1|0 */
    public function index(Request $request)
    {
        return Supplier::query()
            ->when($request->query('search'), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('name', 'ilike', '%'.$s.'%')
                    ->orWhere('contact_name', 'ilike', '%'.$s.'%')
                    ->orWhere('phone', 'ilike', '%'.$s.'%')
            ))
            ->when($request->query('active') !== null, fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderBy('name')
            ->paginate(min((int) $request->query('per_page', 30), 100));
    }

    public function show(Supplier $supplier)
    {
        return $supplier->load(['purchaseOrders' => fn ($q) => $q->latest('id')->limit(20)]);
    }

    public function store(Request $request)
    {
        return response()->json($this->suppliers->create($this->rules($request)), 201);
    }

    public function update(Request $request, Supplier $supplier)
    {
        return $this->suppliers->update($supplier, $this->rules($request, true));
    }

    private function rules(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
