<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlaySession extends Model
{
    use SoftDeletes;

    protected $fillable = ['audio_id', 'session_token', 'user_id', 'ip', 'user_agent', 'used', 'expires_at'];
    protected $dates = ['expires_at'];
}
