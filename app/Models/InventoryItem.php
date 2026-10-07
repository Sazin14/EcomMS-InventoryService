<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends Model
{
    protected $fillable = [
        'variant_id', 'sku', 'product_name', 'variant_label',
        'allow_preorder', 'preorder_limit', 'expected_restock_at',
    ];

    protected $casts = [
        'allow_preorder' => 'boolean',
        'expected_restock_at' => 'date:Y-m-d',
    ];

    public function levels(): HasMany
    {
        return $this->hasMany(StockLevel::class, 'variant_id', 'variant_id');
    }

    public function preorders(): HasMany
    {
        return $this->hasMany(Preorder::class, 'variant_id', 'variant_id');
    }
}
