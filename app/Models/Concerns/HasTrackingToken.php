<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Gives a delivery its permanent Open Tracking token on creation. The token
 * never changes, so every Send History copy of the email records engagement
 * against the same delivery.
 */
trait HasTrackingToken
{
    protected static function bootHasTrackingToken(): void
    {
        static::creating(function ($delivery): void {
            if (empty($delivery->tracking_token)) {
                $delivery->tracking_token = (string) Str::uuid();
            }
        });
    }

    /**
     * Engagement as shown to admins: `unopened`, `direct` or `proxy`. Kept
     * apart from the delivery status, which is the fulfilment state.
     */
    public function engagementStatus(): string
    {
        if ($this->opened_at === null) {
            return 'unopened';
        }

        return $this->opened_client_type === 'proxy' ? 'proxy' : 'direct';
    }

    /**
     * Whether the recipient's mail server acknowledged the message (SMTP 250).
     */
    public function hasSmtpAcceptance(): bool
    {
        return str_contains((string) $this->smtp_response, '250');
    }

    /**
     * Filter by engagement: `opened` (any), `direct`, `proxy` or `unopened`.
     * An empty or unrecognised value leaves the query untouched.
     */
    public function scopeEngagement(Builder $query, ?string $engagement): Builder
    {
        return match ($engagement) {
            'opened' => $query->whereNotNull('opened_at'),
            'direct' => $query->where('opened_client_type', 'direct'),
            'proxy' => $query->where('opened_client_type', 'proxy'),
            'unopened' => $query->whereNull('opened_at'),
            default => $query,
        };
    }

    /**
     * Options for the admin engagement filter dropdown.
     *
     * @return array<string,string>
     */
    public static function engagementFilterLabels(): array
    {
        return [
            'opened' => 'Opened (Any)',
            'direct' => 'Opened (Direct)',
            'proxy' => 'Opened (Proxy)',
            'unopened' => 'Unopened',
        ];
    }
}
