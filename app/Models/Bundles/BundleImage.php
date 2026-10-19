<?php

namespace App\Models\Bundles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class BundleImage extends Model
{
    protected $fillable = [
        'bundle_id',
        'path',
        'original_name',
        'position',
        'shopify_media_id',
        'shopify_url',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function bundle()
    {
        return $this->belongsTo(Bundle::class);
    }

    /**
     * Preview URL: Shopify's CDN copy once synced, otherwise the local upload.
     */
    public function getUrlAttribute(): string
    {
        return $this->shopify_url ?: Storage::disk('public')->url($this->path);
    }
}
