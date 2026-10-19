<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reservation extends Model
{
    public const ACTIVE = 'active';        // stock held, waiting for payment (has expires_at)
    public const CONFIRMED = 'confirmed';  // paid; held until shipped (no expiry)
    public const FULFILLED = 'fulfilled';  // shipped; stock deducted
    public const RELEASED = 'released';    // cancelled; stock freed
    public const EXPIRED = 'expired';      // payment never came; stock freed

    protected $fillable = [
        'reference',
        'status',
        'expires_at',
        'idempotency_key',
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

    // Only an unpaid (active) reservation can expire.
    public function isExpired(): bool
    {
        return $this->status === self::ACTIVE
            && $this->expires_at?->isPast() === true;
    }
}
