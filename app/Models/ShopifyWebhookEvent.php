<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One inbound Shopify webhook delivery, stored before it is processed (the
 * webhook inbox). Shared infrastructure across contexts: it holds no domain
 * state, only the raw payload and its processing outcome.
 */
class ShopifyWebhookEvent extends Model
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PROCESSED = 'processed';

    /** Handled correctly, but there was nothing to do (see `reason`). */
    public const STATUS_IGNORED = 'ignored';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'endpoint',
        'topic',
        'shopify_order_id',
        'shopify_webhook_id',
        'payload',
        'hmac_valid',
        'status',
        'attempts',
        'reason',
        'error',
        'received_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'shopify_order_id' => 'integer',
            'payload' => 'array',
            'hmac_valid' => 'boolean',
            'attempts' => 'integer',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_RECEIVED => 'Received',
            self::STATUS_PROCESSING => 'Processing',
            self::STATUS_PROCESSED => 'Processed',
            self::STATUS_IGNORED => 'Ignored',
            self::STATUS_FAILED => 'Failed',
        ];
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        return filled($status) ? $query->where('status', $status) : $query;
    }
}
