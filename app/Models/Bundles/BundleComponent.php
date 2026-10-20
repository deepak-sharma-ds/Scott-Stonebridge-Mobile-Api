<?php

namespace App\Models\Bundles;

use Illuminate\Database\Eloquent\Model;

class BundleComponent extends Model
{
    protected $fillable = [
        'bundle_id',
        'shopify_product_id',
        'shopify_variant_id',
        'product_title',
        'variant_title',
        'sku',
        'quantity',
        'unit_price',
        'map_price',
        'image_url',
        'product_handle',
        'position',
    ];

    protected $casts = [
        'quantity'   => 'integer',
        'unit_price' => 'decimal:2',
        'map_price'  => 'decimal:2',
        'position'   => 'integer',
    ];

    public function bundle()
    {
        return $this->belongsTo(Bundle::class);
    }

    public function getDisplayTitleAttribute(): string
    {
        $variant = $this->variant_title;

        return $variant && $variant !== 'Default Title'
            ? "{$this->product_title} — {$variant}"
            : $this->product_title;
    }

    public function getLineTotalAttribute(): float
    {
        return round((float) $this->unit_price * $this->quantity, 2);
    }
}
