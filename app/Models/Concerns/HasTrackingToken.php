<?php

namespace App\Models\Concerns;

use App\Support\OpenClientClassifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
        static::creating(function (Model $delivery): void {
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

        return $this->opened_client_type === OpenClientClassifier::PROXY
            ? OpenClientClassifier::PROXY
            : OpenClientClassifier::DIRECT;
    }

    /**
     * Whether the recipient's mail server acknowledged the message: the stored
     * response starts with the SMTP reply code 250 (not merely contains the digits).
     */
    public function hasSmtpAcceptance(): bool
    {
        return (bool) preg_match('/^(?:\[[^\]]*\] < )?250[ -]/m', (string) $this->smtp_response);
    }

    /**
     * Engagement label for exports; blank for a delivery that was never sent.
     */
    public function engagementLabel(): string
    {
        $status = $this->engagementStatus();

        if ($status === 'unopened' && $this->status !== static::STATUS_SENT) {
            return '';
        }

        return static::engagementFilterLabels()[$status];
    }

    /**
     * Filter by engagement: `opened` (any), `direct`, `proxy` or `unopened`.
     * An empty or unrecognised value leaves the query untouched.
     */
    public function scopeEngagement(Builder $query, ?string $engagement): Builder
    {
        return match ($engagement) {
            'opened' => $query->whereNotNull('opened_at'),
            'direct' => $query->where('opened_client_type', OpenClientClassifier::DIRECT),
            'proxy' => $query->where('opened_client_type', OpenClientClassifier::PROXY),
            'unopened' => $query->where('status', 'sent')->whereNull('opened_at'),
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
