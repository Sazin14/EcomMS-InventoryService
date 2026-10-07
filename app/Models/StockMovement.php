<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'variant_id',
        'sku',
        'warehouse_id',
        'type',
        'quantity',
        'reference_type',
        'reference_id',
        'reason',
        'created_by_type',
        'created_by_id',
    ];

    protected $casts = [
        'variant_id' => 'integer',
        'warehouse_id' => 'integer',
        'quantity' => 'integer',
        'created_by_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
