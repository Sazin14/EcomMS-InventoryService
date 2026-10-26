<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutboxMessage extends Model
{
    protected $fillable = [
        'message_id', 'routing_key', 'payload', 'occurred_at',
        'published_at', 'attempts', 'last_error', 'available_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'published_at' => 'datetime',
        'available_at' => 'datetime',
    ];
}
