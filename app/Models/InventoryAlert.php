<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryAlert extends Model
{
    protected $fillable = ['type', 'variant_id', 'sku', 'quantity', 'message', 'read_at'];

    protected $casts = ['read_at' => 'datetime'];
}
