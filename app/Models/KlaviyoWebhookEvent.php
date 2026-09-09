<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KlaviyoWebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_key',
        'flow_id',
        'recipient_email',
        'payload',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }

    /**
     * Klaviyo flow webhooks carry no event id, so idempotency is derived
     * from the payload itself. The hour bucket collapses Klaviyo's retry
     * storms into one key while still allowing a legitimately re-triggered
     * flow (days later) to notify the same profile again.
     */
    public static function buildEventKey(string $flowId, string $messageId, string $email): string
    {
        return sha1(implode('|', [
            $flowId,
            $messageId,
            strtolower(trim($email)),
            (string) intdiv(time(), 3600),
        ]));
    }
}
