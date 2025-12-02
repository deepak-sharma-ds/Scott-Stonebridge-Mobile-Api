<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlaybackSession extends Model
{
    protected $table = 'playback_sessions';

    protected $fillable = [
        'customer_id',
        'audio_id',
        'package_tag',
        'last_position_seconds',
    ];
}
