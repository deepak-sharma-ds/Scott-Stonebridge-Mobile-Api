<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Filter by delivery status when a non-empty status is given.
     */
    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        if ($status === null || $status === '') {
            return $query;
        }

        return $query->where('status', $status);
    }

    /**
     * Match the term against customer email/name or the Shopify order id.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('customer_email', 'like', "%{$term}%")
                ->orWhere('customer_name', 'like', "%{$term}%")
                ->orWhere('shopify_order_id', 'like', "%{$term}%");
        });
    }

    /**
     * Restrict to deliveries whose Campaign Product belongs to the given
     * Marketing Campaign.
     */
    public function scopeCampaign(Builder $query, ?int $marketingCampaignId): Builder
    {
        if (! $marketingCampaignId) {
            return $query;
        }

        return $query->whereHas(
            'campaignProduct',
            fn (Builder $q) => $q->where('marketing_campaign_id', $marketingCampaignId)
        );
    }

    /**
     * Human-readable status labels for the admin UI.
     *
     * @return array<string,string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING => 'Pending',
            self::STATUS_GENERATED => 'Generated',
            self::STATUS_SENT => 'Sent',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }
}
