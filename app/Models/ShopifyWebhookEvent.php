<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopifyWebhookEvent extends Model
{
    protected $fillable = [
        'topic',
        'shopify_order_id',
        'shopify_webhook_id',
        'payload',
        'hmac_valid',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'shopify_order_id' => 'integer',
            'payload' => 'array',
            'hmac_valid' => 'boolean',
            'received_at' => 'datetime',
        ];
    }
}
