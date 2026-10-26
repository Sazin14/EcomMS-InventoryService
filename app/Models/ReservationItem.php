<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationItem extends Model
{
    protected $fillable = [
        'reservation_id',
        'variant_id',
        'sku',
        'warehouse_id',
        'quantity',
    ];

    protected $casts = [
        'reservation_id' => 'integer',
        'variant_id' => 'integer',
        'warehouse_id' => 'integer',
        'quantity' => 'integer',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
