<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Preorder extends Model
{
    protected $fillable = ['reference', 'variant_id', 'quantity', 'status', 'reservation_id', 'allocated_at'];

    protected $casts = ['allocated_at' => 'datetime'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'variant_id', 'variant_id');
    }
}
