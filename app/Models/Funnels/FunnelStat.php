<?php

namespace App\Models\Funnels;

use Illuminate\Database\Eloquent\Model;

class FunnelStat extends Model
{
    public $timestamps = false;

    public const COUNTERS = ['impressions', 'accepts', 'rejects', 'checkout_initiates', 'conversions'];

    protected $fillable = ['funnel_id', 'date', 'impressions', 'accepts', 'rejects', 'checkout_initiates', 'conversions', 'revenue'];

    // 'date' stays a plain Y-m-d string so daily lookups match on every database.
    protected $casts = [
        'revenue' => 'decimal:2',
    ];

    public function funnel()
    {
        return $this->belongsTo(Funnel::class)->withTrashed();
    }
}
