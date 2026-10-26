<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    protected $fillable = ['purchase_order_id', 'variant_id', 'sku', 'quantity_ordered', 'quantity_received', 'unit_cost'];

    protected $casts = [
        'variant_id' => 'integer',
        'quantity_ordered' => 'integer',
        'quantity_received' => 'integer',
        'unit_cost' => 'decimal:2',
    ];

    protected $appends = ['remaining'];

    public function getRemainingAttribute(): int
    {
        return (int) $this->quantity_ordered - (int) $this->quantity_received;
    }
}
