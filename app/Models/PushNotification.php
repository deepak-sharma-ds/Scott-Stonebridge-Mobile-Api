<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushNotification extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const SOURCE_FLOW = 'flow';

    public const SOURCE_CAMPAIGN = 'campaign';

    protected $fillable = [
        'source_type',
        'source_id',
        'message_id',
        'recipient_email',
        'device_token_id',
        'title',
        'body',
        'data',
        'status',
        'fcm_message_id',
        'error_code',
        'error_message',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
            'cleared_at' => 'datetime',
        ];
    }

    public function deviceToken(): BelongsTo
    {
        return $this->belongsTo(DeviceToken::class);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeRead(Builder $query): Builder
    {
        return $query->whereNotNull('read_at');
    }

    /**
     * Notifications the customer hasn't cleared from their in-app list.
     * Clearing only hides a row from the app; the delivery record stays.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNull('cleared_at');
    }

    public function markRead(): void
    {
        if ($this->read_at === null) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }

    public function clear(): void
    {
        if ($this->cleared_at === null) {
            $this->forceFill(['cleared_at' => now()])->save();
        }
    }

    public function markSent(string $fcmMessageId): void
    {
        $this->forceFill([
            'status' => self::STATUS_SENT,
            'fcm_message_id' => $fcmMessageId,
            'sent_at' => now(),
        ])->save();
    }

    public function markFailed(string $code, string $message): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error_code' => $code,
            'error_message' => $message,
        ])->save();
    }
}
