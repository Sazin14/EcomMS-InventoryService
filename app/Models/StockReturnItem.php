<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockReturnItem extends Model
{
    public const RESELLABLE = 'resellable';
    public const DAMAGED = 'damaged';

    protected $fillable = ['stock_return_id', 'variant_id', 'sku', 'warehouse_id', 'quantity', 'condition'];
}
