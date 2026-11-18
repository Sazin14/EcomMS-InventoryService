<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reservation extends Model
{
    public const ACTIVE = 'active';
    public const CONFIRMED = 'confirmed';
    public const FULFILLED = 'fulfilled';
    public const RELEASED = 'released';
    public const EXPIRED = 'expired';

    protected $fillable = [
        'reference',
        'status',
        'expires_at',
        'idempotency_key',
        'customer_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ReservationItem::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function isExpired(): bool
    {
        return $this->status === self::ACTIVE
            && $this->expires_at?->isPast() === true;
    }
}
