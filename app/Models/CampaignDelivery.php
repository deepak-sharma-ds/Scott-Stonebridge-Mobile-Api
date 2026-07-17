<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_GENERATED = 'generated';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'shopify_order_id',
        'shopify_line_item_id',
        'campaign_product_id',
        'customer_email',
        'customer_name',
        'status',
        'sent_at',
        'scheduled_at',
        'expedited_at',
        'fulfilled_at',
        'attempts',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'shopify_order_id' => 'integer',
            'shopify_line_item_id' => 'integer',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'expedited_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
    }

    public function campaignProduct(): BelongsTo
    {
        return $this->belongsTo(CampaignProduct::class);
    }

    public function markFailed(string $message): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error_message' => $message,
        ])->save();
    }
}
