<?php

namespace App\Models;

use App\Models\Concerns\HasTrackingToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailReadingDelivery extends Model
{
    use HasTrackingToken;

    public const STATUS_PENDING = 'pending';

    public const STATUS_GENERATED = 'generated';

    /** Claimed by exactly one send job; the email is being (or is about to be) sent. */
    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** Quarantined by the Compliance Gate: no generation, no email until an admin decides. */
    public const STATUS_COMPLIANCE_HOLD = 'compliance_hold';

    protected $fillable = [
        'shopify_order_id',
        'shopify_line_item_id',
        'email_reading_product_id',
        'customer_email',
        'customer_name',
        'questions',
        'ai_response',
        'prompt_tokens',
        'completion_tokens',
        'model_used',
        'status',
        'error_message',
        'attempts',
        'sent_at',
        'scheduled_at',
        'expedited_at',
        'fulfilled_at',
        'claim_token',
        'claimed_at',
        'release_count',
        'sent_manually',
        'fulfillment_error',
        'compliance_flagged_reason',
        'compliance_reviewed_at',
        'theme_summary',
        'embedding',
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
            'questions' => 'array',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'expedited_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'claimed_at' => 'datetime',
            'release_count' => 'integer',
            'sent_manually' => 'boolean',
            'compliance_reviewed_at' => 'datetime',
            'embedding' => 'array',
            'send_history' => 'array',
            'dispatched_at' => 'datetime',
            'opened_at' => 'datetime',
            'last_opened_at' => 'datetime',
            'open_count' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(EmailReadingProduct::class, 'email_reading_product_id');
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
            self::STATUS_COMPLIANCE_HOLD => 'Compliance Hold',
        ];
    }

    /**
     * Atomically quarantine a not-yet-generated delivery for admin review.
     * Never overrides a terminal or in-flight state. Returns whether the row
     * was actually updated.
     */
    public function markComplianceHold(string $reason): bool
    {
        $attributes = [
            'status' => self::STATUS_COMPLIANCE_HOLD,
            'compliance_flagged_reason' => mb_substr($reason, 0, 500),
            'error_message' => null,
        ];

        $updated = static::query()
            ->whereKey($this->getKey())
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_FAILED, self::STATUS_GENERATED])
            ->update($attributes);

        if ($updated === 1) {
            $this->forceFill($attributes)->syncOriginal();
        }

        return $updated === 1;
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
     * Single source of truth for converting Shopify property names and
     * schema keys/labels into the canonical lookup key used inside the
     * delivery's `questions` array. Identical input must always produce
     * identical output — both the webhook controller (when mapping
     * incoming properties) and the processing job (when resolving schema
     * slots) call this so they cannot drift.
     */
    public static function normalizeKey(?string $value): string
    {
        $value = (string) $value;
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/i', '_', $value);

        return trim($value, '_');
    }
}
