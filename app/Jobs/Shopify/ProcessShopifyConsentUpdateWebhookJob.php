<?php

declare(strict_types=1);

namespace App\Jobs\Shopify;

use App\Models\SyncEvent;
use App\Services\KlaviyoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Processes one Shopify customer marketing-consent webhook payload. This is
 * the same per-channel sync logic that used to run inline in
 * ShopifyWebhookController::consentUpdate() - moved here unchanged so
 * Shopify gets an immediate webhook ack and a slow/unreachable Klaviyo call
 * no longer holds the request open. If a Klaviyo write fails, this job
 * throws so the queue retries it and, once tries are exhausted, it lands in
 * `failed_jobs` for replay via `php artisan queue:retry`.
 */
class ProcessShopifyConsentUpdateWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    /**
     * @param  array<string, mixed>  $payload  Raw Shopify consent-update webhook body
     */
    public function __construct(
        public readonly array $payload,
    ) {}

    public function handle(KlaviyoService $klaviyo): void
    {
        $customerId = $this->payload['customer_id'] ?? null;
        $email = $this->payload['email_address'] ?? $this->payload['email'] ?? null;
        $phone = $this->payload['phone'] ?? null;

        $consentUpdates = [
            [
                'identifier' => $email,
                'channel' => 'email',
                'state' => $this->payload['email_marketing_consent']['state'] ?? null,
            ],
            [
                'identifier' => $phone,
                'channel' => 'sms',
                'state' => $this->payload['sms_marketing_consent']['state'] ?? null,
            ],
        ];

        $hadFailure = false;

        foreach ($consentUpdates as $consentUpdate) {
            $identifier = $consentUpdate['identifier'];
            $channel = $consentUpdate['channel'];
            $state = $consentUpdate['state'];

            if ($identifier && $channel === 'sms') {
                $identifier = $klaviyo->normalizePhoneNumber($identifier) ?? $identifier;
            }

            if (! $identifier || ! $state) {
                continue;
            }

            if (
                ! app()->environment('production')
                && $channel === 'email'
                && ! str_ends_with(strtolower($identifier), '@dotsquares.com')
            ) {
                continue;
            }

            Log::channel('klaviyo_marketing_consent')->info('SHOPIFY MARKETING CONSENT WEBHOOK', [
                'customer_id' => $customerId,
                'identifier' => $identifier,
                'channel' => $channel,
                'state' => $state,
            ]);

            if (SyncEvent::wasJustWrittenByUs($identifier, $channel, $state)) {
                Log::channel('klaviyo_marketing_consent')->info('Skipping Shopify → Klaviyo sync because it was already written by us', [
                    'identifier' => $identifier,
                    'channel' => $channel,
                    'state' => $state,
                ]);

                continue;
            }

            if ($state === 'subscribed') {
                // Klaviyo will not let the API force a profile back to
                // SUBSCRIBED once it has an explicit unsubscribe/suppression
                // on file - it's a compliance guardrail (CAN-SPAM/GDPR), not
                // a bug on our end, and calling subscribe() here just
                // silently no-ops for suppressed profiles. Left commented
                // (not removed) in case Klaviyo ever changes this.
                // $success = $klaviyo->subscribe($identifier, $channel);

                Log::channel('klaviyo_marketing_consent')->warning(
                    '[KLAVIYO_SUBSCRIBE_SKIPPED] Skipped: Klaviyo does not allow resubscribing a profile via API (compliance restriction), not an error',
                    [
                        'event' => 'klaviyo_subscribe_skipped_compliance',
                        'customer_id' => $customerId,
                        'identifier' => $identifier,
                        'channel' => $channel,
                        'state' => $state,
                    ]
                );

                continue;
            } elseif ($state === 'unsubscribed') {
                $success = $klaviyo->unsubscribe($identifier, $channel);
            } else {
                Log::channel('klaviyo_marketing_consent')->warning('Unknown Shopify marketing consent state', [
                    'identifier' => $identifier,
                    'channel' => $channel,
                    'state' => $state,
                ]);

                continue;
            }

            Log::channel('klaviyo_marketing_consent')->info('Shopify → Klaviyo marketing consent sync', [
                'customer_id' => $customerId,
                'identifier' => $identifier,
                'channel' => $channel,
                'state' => $state,
                'success' => $success,
            ]);

            if (! $success) {
                $hadFailure = true;
            }
        }

        if ($hadFailure) {
            throw new RuntimeException(
                "Klaviyo marketing consent sync failed for Shopify customer {$customerId}; see klaviyo_marketing_consent log for details."
            );
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('klaviyo_marketing_consent')->error('ProcessShopifyConsentUpdateWebhookJob failed permanently', [
            'customer_id' => $this->payload['customer_id'] ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
}
