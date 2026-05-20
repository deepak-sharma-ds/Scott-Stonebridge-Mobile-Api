<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConversionEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Append-only funnel event row. Inserted by StoreConversionEventJob.
 *
 * @property int $id
 * @property string $session_id
 * @property string $shop_domain
 * @property string $event_type
 * @property string|null $product_id
 * @property string|null $order_id
 * @property float|null $revenue
 * @property array<string, mixed>|null $metadata_json
 * @property Carbon $created_at
 */
class ConversionEvent extends Model
{
    /** @use HasFactory<ConversionEventFactory> */
    use HasFactory;

    // Append-only — disable Eloquent's updated_at handling entirely.
    public const UPDATED_AT = null;

    public const EVENT_CHAT_OPENED = 'chat_opened';

    public const EVENT_MESSAGE_SENT = 'message_sent';

    public const EVENT_PRODUCT_CLICKED = 'product_clicked';

    public const EVENT_UPSELL_CLICKED = 'upsell_clicked';

    public const EVENT_UPSELL_ADDED_TO_CART = 'upsell_added_to_cart';

    public const EVENT_LEAD_CAPTURED = 'lead_captured';

    public const EVENT_CHECKOUT_STARTED = 'checkout_started';

    public const EVENT_ORDER_PLACED = 'order_placed';

    public const EVENT_ABANDON_RECOVERY_SENT = 'abandon_recovery_sent';

    public const EVENT_TRIGGER_FIRED = 'trigger_fired';

    public const EVENT_TRIGGER_OPENED = 'trigger_opened';

    public const EVENT_TRIGGER_DISMISSED = 'trigger_dismissed';

    public const EVENT_ESCALATION_TRIGGERED = 'escalation_triggered';

    public const EVENT_CHAT_CLOSED = 'chat_closed';

    protected $fillable = [
        'session_id',
        'shop_domain',
        'event_type',
        'product_id',
        'order_id',
        'revenue',
        'metadata_json',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'revenue' => 'decimal:2',
            'metadata_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<ConversionEvent>  $query
     */
    public function scopeForShop(Builder $query, string $shopDomain): Builder
    {
        return $query->where('shop_domain', $shopDomain);
    }

    /**
     * @param  Builder<ConversionEvent>  $query
     */
    public function scopeForSession(Builder $query, string $sessionId): Builder
    {
        return $query->where('session_id', $sessionId);
    }

    /**
     * @param  Builder<ConversionEvent>  $query
     */
    public function scopeOfType(Builder $query, string $eventType): Builder
    {
        return $query->where('event_type', $eventType);
    }
}
