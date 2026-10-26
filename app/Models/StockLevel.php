<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLevel extends Model
{
    protected $fillable = [
        'variant_id',
        'sku',
        'warehouse_id',
        'on_hand',
        'reserved',
        'reorder_level',
    ];

    protected $casts = [
        'variant_id' => 'integer',
        'warehouse_id' => 'integer',
        'on_hand' => 'integer',
        'reserved' => 'integer',
        'reorder_level' => 'integer',
    ];

    protected $appends = ['available'];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function getAvailableAttribute(): int
    {
        return $this->on_hand - $this->reserved;
    }



    public function isOutOfStock(): bool
    {
        return $this->available <= 0;
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'variant_id', 'variant_id');
    }

    public function isLowStock(): bool
    {
        return $this->reorder_level > 0
            && $this->available > 0
            && $this->available <= $this->reorder_level;
    }


}
