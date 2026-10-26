<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Concerns\FailsValidation;

class SupplierService
{
    use FailsValidation;

    public function create(array $data): Supplier
    {
        return Supplier::create($data);
    }

    public function update(Supplier $supplier, array $data): Supplier
    {
        // Deactivating a supplier with goods still on the way would hide an open order.
        if (array_key_exists('is_active', $data) && ! $data['is_active']
            && PurchaseOrder::where('supplier_id', $supplier->id)->whereIn('status', PurchaseOrder::OPEN)->exists()) {
            $this->fail('is_active', 'This supplier still has open purchase orders.');
        }

        $supplier->update($data);

        return $supplier->fresh();
    }
}
