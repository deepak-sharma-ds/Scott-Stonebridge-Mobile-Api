<?php

namespace App\Models;

use App\Models\Concerns\HasTrackingToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignDelivery extends Model
{
    use HasTrackingToken;

    public const STATUS_PENDING = 'pending';

    public const STATUS_GENERATED = 'generated';

    /** Claimed by exactly one send job; the email is being (or is about to be) sent. */
    public const STATUS_SENDING = 'sending';

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
        'claim_token',
        'claimed_at',
        'release_count',
        'sent_manually',
        'fulfillment_error',
        'tracking_token',
        'message_id',
        'smtp_response',
        'send_history',
        'dispatched_at',
        'opened_at',
        'last_opened_at',
        'open_count',
        'opened_client_type',
        'opened_ip',
        'opened_user_agent',
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
            'claimed_at' => 'datetime',
            'release_count' => 'integer',
            'sent_manually' => 'boolean',
            'send_history' => 'array',
            'dispatched_at' => 'datetime',
            'opened_at' => 'datetime',
            'last_opened_at' => 'datetime',
            'open_count' => 'integer',
        ];
    }

    public function campaignProduct(): BelongsTo
    {
        return $this->belongsTo(CampaignProduct::class);
    }

    /**
     * Atomically flag the delivery failed. Never overwrites a terminal
     * `sent`/`cancelled` state (e.g. a refund that landed mid-flight) and
     * releases any send claim. Returns whether the row was actually updated.
     */
    public function markFailed(string $message): bool
    {
        $attributes = [
            'status' => self::STATUS_FAILED,
            'error_message' => $message,
            'claim_token' => null,
            'claimed_at' => null,
        ];

        $updated = static::query()
            ->whereKey($this->getKey())
            ->whereNotIn('status', [self::STATUS_SENT, self::STATUS_CANCELLED])
            ->update($attributes);

        if ($updated === 1) {
            $this->forceFill($attributes)->syncOriginal();
        }

        return $updated === 1;
    }

    /**
     * A sent delivery whose Shopify fulfilment is still outstanding.
     */
    public function needsFulfillment(): bool
    {
        return $this->status === self::STATUS_SENT && $this->fulfilled_at === null;
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
            self::STATUS_SENDING => 'Sending',
            self::STATUS_SENT => 'Sent',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }
}
