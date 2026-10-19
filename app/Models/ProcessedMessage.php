<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessedMessage extends Model
{
    protected $primaryKey = 'message_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['message_id', 'consumer', 'processed_at'];

    protected $casts = ['processed_at' => 'datetime'];
}
