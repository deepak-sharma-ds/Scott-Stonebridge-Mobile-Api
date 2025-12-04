<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsSnapshot extends Model
{
    public $timestamps = false;
    protected $fillable = ['key', 'payload', 'created_at'];
    protected $casts = ['payload' => 'array', 'created_at' => 'datetime'];
}
