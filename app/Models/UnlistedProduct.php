<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UnlistedProduct extends Model
{
    protected $fillable = [
        'shopify_product_id',
        'title',
        'shopify_image_url',
        'variants',
        'header_image',
        'email_content',
        'email_footer',
        'is_published',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'shopify_product_id' => 'integer',
            'variants' => 'array',
            'is_published' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }
}
