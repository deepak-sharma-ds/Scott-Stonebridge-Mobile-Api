<?php

namespace App\Models\Funnels;

use Illuminate\Database\Eloquent\Model;

class FunnelOrder extends Model
{
    protected $fillable = [
        'funnel_id', 'shopify_order_id', 'order_name', 'currency', 'order_total',
        'attributed_revenue', 'discount_amount', 'placement', 'ordered_at',
    ];

    protected $casts = [
        'order_total'        => 'decimal:2',
        'attributed_revenue' => 'decimal:2',
        'discount_amount'    => 'decimal:2',
        'ordered_at'         => 'datetime',
    ];

    public function funnel()
    {
        return $this->belongsTo(Funnel::class)->withTrashed();
    }
}
