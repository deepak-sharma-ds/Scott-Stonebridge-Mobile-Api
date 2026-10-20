<?php

namespace App\Models\Bundles;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class BundleSyncLog extends Model
{
    protected $fillable = [
        'bundle_id',
        'funnel_id',
        'operation',
        'status',
        'details',
        'error',
        'duration_ms',
        'user_id',
    ];

    protected $casts = [
        'details'     => 'array',
        'duration_ms' => 'integer',
    ];

    public function bundle()
    {
        return $this->belongsTo(Bundle::class)->withTrashed();
    }

    public function funnel()
    {
        return $this->belongsTo(\App\Models\Funnels\Funnel::class)->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
