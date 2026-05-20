<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AiLeadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * AiLead — email + cart snapshot captured mid-conversation.
 *
 * Lifecycle: new -> recovery_sent -> converted (or unsubscribed).
 *
 * @property int $id
 * @property string $session_id
 * @property string $shop_domain
 * @property string $email
 * @property string|null $name
 * @property string|null $issue_summary
 * @property array<string, mixed>|null $cart_snapshot_json
 * @property string $source
 * @property string $status
 * @property Carbon|null $recovery_sent_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AiLead extends Model
{
    /** @use HasFactory<AiLeadFactory> */
    use HasFactory;

    public const SOURCE_PROACTIVE_TRIGGER = 'proactive_trigger';

    public const SOURCE_MANUAL_INPUT = 'manual_input';

    public const SOURCE_ESCALATION = 'escalation';

    public const STATUS_NEW = 'new';

    public const STATUS_RECOVERY_SENT = 'recovery_sent';

    public const STATUS_CONVERTED = 'converted';

    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    protected $fillable = [
        'session_id',
        'shop_domain',
        'email',
        'name',
        'issue_summary',
        'cart_snapshot_json',
        'source',
        'status',
        'recovery_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'cart_snapshot_json' => 'array',
            'recovery_sent_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<AiLead>  $query
     */
    public function scopeStatusNew(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_NEW);
    }

    /**
     * @param  Builder<AiLead>  $query
     */
    public function scopeForSession(Builder $query, string $sessionId): Builder
    {
        return $query->where('session_id', $sessionId);
    }

    public function hasCartItems(): bool
    {
        $snapshot = $this->cart_snapshot_json ?? [];

        return (int) ($snapshot['item_count'] ?? 0) > 0;
    }
}
