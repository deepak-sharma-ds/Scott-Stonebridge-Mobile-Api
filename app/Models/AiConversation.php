<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AiConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * AiConversation
 *
 * @property int $id
 * @property string $session_id
 * @property string|null $shopify_customer_id
 * @property string $shop_domain
 * @property string|null $page_type
 * @property string|null $locale
 * @property string $status
 * @property array<string, mixed>|null $metadata
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AiConversation extends Model
{
    /** @use HasFactory<AiConversationFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    public const STATUS_ESCALATED = 'escalated';

    public const CONVERSION_DIRECT = 'direct';

    public const CONVERSION_ASSISTED = 'assisted';

    public const CONVERSION_ABANDONED = 'abandoned';

    protected $fillable = [
        'session_id',
        'shopify_customer_id',
        'shop_domain',
        'page_type',
        'locale',
        'status',
        'metadata',
        'started_at',
        'ended_at',
        'revenue_attributed',
        'conversion_type',
        'lead_captured',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'revenue_attributed' => 'decimal:2',
            'lead_captured' => 'boolean',
        ];
    }

    /**
     * @return HasMany<AiMessage>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'conversation_id');
    }

    /**
     * @param  Builder<AiConversation>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
