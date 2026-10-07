<?php

namespace App\Services;

use App\Models\StockLevel;
use App\Models\Warehouse;
use App\Services\Concerns\FailsValidation;
use Illuminate\Support\Facades\DB;

class WarehouseService
{
    use FailsValidation;

    public function create(array $data): Warehouse
    {
        return DB::transaction(function () use ($data) {
            // the very first warehouse becomes the default
            if (! Warehouse::where('is_default', true)->exists()) {
                $data['is_default'] = true;
            }

            if (! empty($data['is_default'])) {
                Warehouse::where('is_default', true)->update(['is_default' => false]);
            }

            return Warehouse::create($data);
        });
    }

    public function update(Warehouse $warehouse, array $data): Warehouse
    {
        return DB::transaction(function () use ($warehouse, $data) {
            if ($warehouse->is_default && array_key_exists('is_default', $data) && ! $data['is_default']) {
                $this->fail('is_default', 'Make another warehouse the default instead.');
            }

            if ($warehouse->is_default && array_key_exists('is_active', $data) && ! $data['is_active']) {
                $this->fail('is_active', 'The default warehouse cannot be deactivated.');
            }

            if (array_key_exists('is_active', $data) && ! $data['is_active']
                && StockLevel::where('warehouse_id', $warehouse->id)->where('reserved', '>', 0)->exists()) {
                $this->fail('is_active', 'This warehouse still has reserved stock.');
            }

            if (! empty($data['is_default'])) {
                Warehouse::where('is_default', true)->where('id', '!=', $warehouse->id)->update(['is_default' => false]);
            }

            $warehouse->update($data);

            return $warehouse->fresh();
        });
    }
}
