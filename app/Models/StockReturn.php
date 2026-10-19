<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockReturn extends Model
{
    protected $fillable = [
        'reservation_id', 'reference', 'idempotency_key', 'reason', 'received_by_type', 'received_by_id',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(StockReturnItem::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
