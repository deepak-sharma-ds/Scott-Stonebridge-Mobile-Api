<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailReadingDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_GENERATED = 'generated';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

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
            self::STATUS_SENT => 'Sent',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    public function markFailed(string $message): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error_message' => $message,
        ])->save();
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
