<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reservation extends Model
{
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
        return $this->status === 'active';
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null
            && $this->expires_at->isPast();
    }
}
