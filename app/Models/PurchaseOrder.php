<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    public const DRAFT = 'draft';
    public const ORDERED = 'ordered';
    public const PARTIALLY_RECEIVED = 'partially_received';
    public const RECEIVED = 'received';
    public const CLOSED = 'closed';
    public const CANCELLED = 'cancelled';

    public const OPEN = [self::ORDERED, self::PARTIALLY_RECEIVED];

    protected $fillable = [
        'number', 'supplier_id', 'warehouse_id', 'status', 'expected_at', 'notes',
        'ordered_at', 'received_at', 'closed_at', 'cancelled_at', 'created_by_id',
    ];

    protected $casts = [
        'expected_at' => 'date:Y-m-d',
        'ordered_at' => 'datetime',
        'received_at' => 'datetime',
        'closed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class);
    }
}
