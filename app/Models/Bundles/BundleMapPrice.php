<?php

namespace App\Models\Bundles;

use Illuminate\Database\Eloquent\Model;

class BundleMapPrice extends Model
{
    protected $fillable = [
        'sku',
        'map_price',
        'notes',
        'updated_by',
    ];

    protected $casts = [
        'map_price' => 'decimal:2',
    ];

    public function setSkuAttribute($value): void
    {
        $this->attributes['sku'] = trim((string) $value);
    }
}
