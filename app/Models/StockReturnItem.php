<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockReturnItem extends Model
{
    public const RESELLABLE = 'resellable';   // goes back into sellable stock
    public const DAMAGED = 'damaged';         // recorded, NOT added back to sellable stock

    protected $fillable = ['stock_return_id', 'variant_id', 'sku', 'warehouse_id', 'quantity', 'condition'];
}
