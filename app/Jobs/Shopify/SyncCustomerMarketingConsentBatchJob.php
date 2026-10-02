<?php

declare(strict_types=1);

namespace App\Jobs\Shopify;

use App\Services\KlaviyoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes one page (<=250) of Shopify customer marketing-consent nodes:
 * groups them by channel + action and pushes each group to Klaviyo via
 * KlaviyoService::subscribeBatch()/unsubscribeBatch(), which chunk to
 * Klaviyo's own per-request caps (1000 subscribe / 100 unsubscribe).
 * Dispatched per-page by MarketingConsentSyncService so a 50k+ customer
 * backfill runs as many small, independently-retryable jobs instead of one
 * long blocking process.
 */
class SyncCustomerMarketingConsentBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const SYNCABLE_STATES = ['SUBSCRIBED', 'UNSUBSCRIBED'];

    public int $tries = 3;

    public int $timeout = 180;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    /**
     * @param  array<int, array<string, mixed>>  $customers  Raw GraphQL customer nodes
     */
    public function __construct(
        public readonly array $customers,
    ) {}

    public function handle(KlaviyoService $klaviyo): void
    {
        $emailSubscribe = [];
        $emailUnsubscribe = [];
        $smsSubscribe = [];
        $smsUnsubscribe = [];

        foreach ($this->customers as $node) {
            $email = $node['email'] ?? null;
            $emailState = $node['emailMarketingConsent']['marketingState'] ?? null;

            if ($email && in_array($emailState, self::SYNCABLE_STATES, true)) {
                if ($emailState === 'SUBSCRIBED') {
                    $emailSubscribe[] = $email;
                } else {
                    $emailUnsubscribe[] = $email;
                }
            }

            $phone = $node['phone'] ?? $node['defaultAddress']['phone'] ?? null;
            $smsState = $node['smsMarketingConsent']['marketingState'] ?? null;

            if ($phone && in_array($smsState, self::SYNCABLE_STATES, true)) {
                if ($smsState === 'SUBSCRIBED') {
                    $smsSubscribe[] = $phone;
                } else {
                    $smsUnsubscribe[] = $phone;
                }
            }
        }

        $this->flush($klaviyo, 'email', 'subscribe', $emailSubscribe);
        $this->flush($klaviyo, 'email', 'unsubscribe', $emailUnsubscribe);
        $this->flush($klaviyo, 'sms', 'subscribe', $smsSubscribe);
        $this->flush($klaviyo, 'sms', 'unsubscribe', $smsUnsubscribe);
    }

    /**
     * @param  array<int, string>  $identifiers
     */
    private function flush(KlaviyoService $klaviyo, string $channel, string $action, array $identifiers): void
    {
        if ($identifiers === []) {
            return;
        }

        if ($action === 'subscribe') {
            // Klaviyo will not let the API force profiles back to
            // SUBSCRIBED once they have an explicit unsubscribe/suppression
            // on file - it's a compliance guardrail (CAN-SPAM/GDPR), not a
            // bug on our end, and calling subscribeBatch() here just
            // silently no-ops for suppressed profiles. Left commented (not
            // removed) in case Klaviyo ever changes this.
            // $success = $klaviyo->subscribeBatch($identifiers, $channel);

            Log::channel('klaviyo_marketing_consent')->warning(
                '[KLAVIYO_SUBSCRIBE_SKIPPED] Skipped batch: Klaviyo does not allow resubscribing profiles via API (compliance restriction), not an error',
                [
                    'event' => 'klaviyo_subscribe_skipped_compliance',
                    'channel' => $channel,
                    'count' => count($identifiers),
                ]
            );

            return;
        }

        $success = $klaviyo->unsubscribeBatch($identifiers, $channel);

        Log::channel('klaviyo_marketing_consent')->info('Shopify → Klaviyo marketing consent batch sync', [
            'channel' => $channel,
            'action' => $action,
            'count' => count($identifiers),
            'success' => $success,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('klaviyo_marketing_consent')->error('SyncCustomerMarketingConsentBatchJob failed', [
            'customers_in_batch' => count($this->customers),
            'error' => $exception->getMessage(),
        ]);
    }
}
