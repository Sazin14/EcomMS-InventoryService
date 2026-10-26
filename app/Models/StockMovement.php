<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_RECEIPT = 'receipt';
    public const TYPE_SALE = 'sale';
    public const TYPE_RETURN = 'return';
    public const TYPE_ADJUSTMENT = 'adjustment';

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

    // The ledger is append-only: correct mistakes with a new "adjustment" movement.
    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Stock movements are append-only.'));
        static::deleting(fn () => throw new \LogicException('Stock movements are append-only.'));
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
