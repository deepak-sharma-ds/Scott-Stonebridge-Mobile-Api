<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiLog extends Model
{
    protected $fillable = ['actor_type', 'actor_id', 'action', 'meta', 'ip'];

    protected $casts = [
        'meta' => 'array',
    ];
}
