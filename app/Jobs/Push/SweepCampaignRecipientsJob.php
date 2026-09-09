<?php

namespace App\Jobs\Push;

use App\Contracts\Klaviyo\KlaviyoApiClientInterface;
use App\Models\KlaviyoCampaignSweep;
use App\Models\PushNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pages the Klaviyo Events API for a single SENT campaign's "Received Email"
 * recipients and fans a push out to each. The cursor is persisted per page so
 * a failure resumes where it left off; downstream PushNotification unique
 * index makes re-sweeps safe.
 */
class SweepCampaignRecipientsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public int $sweepId) {}

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(KlaviyoApiClientInterface $klaviyo): void
    {
        if (! config('push.enabled', false) || ! config('push.sweep.enabled', false)) {
            return;
        }

        /** @var KlaviyoCampaignSweep|null $sweep */
        $sweep = KlaviyoCampaignSweep::find($this->sweepId);

        if (! $sweep || $sweep->status === KlaviyoCampaignSweep::STATUS_COMPLETED) {
            return;
        }

        $allowlist = (array) config('push.test_emails', []);
        $cursor = $sweep->events_cursor;
        $recipients = $sweep->recipients_found;
        $dispatched = $sweep->pushes_dispatched;

        do {
            $page = $klaviyo->getReceivedEmailEvents($sweep->campaign_id, $cursor, $sweep->send_time);

            foreach ($page['events'] as $event) {
                $email = strtolower(trim((string) ($event['email'] ?? '')));
                if ($email === '') {
                    continue;
                }

                $recipients++;

                if (! empty($allowlist) && ! in_array($email, $allowlist, true)) {
                    continue;
                }

                SendPushToRecipientJob::dispatch(
                    $email,
                    PushNotification::SOURCE_CAMPAIGN,
                    $sweep->campaign_id,
                    $sweep->campaign_id,
                    (string) ($sweep->title ?? config('push.defaults.title')),
                    (string) ($sweep->body ?? config('push.defaults.body')),
                    (string) config('push.defaults.deep_link'),
                    $sweep->content,
                )
                    ->onConnection(config('push.queue.connection'))
                    ->onQueue(config('push.queue.default'));

                $dispatched++;
            }

            $cursor = $page['next_cursor'];

            // Persist progress after each page so a crash resumes cleanly.
            $sweep->forceFill([
                'events_cursor' => $cursor,
                'recipients_found' => $recipients,
                'pushes_dispatched' => $dispatched,
            ])->save();
        } while ($cursor !== null);

        $sweep->forceFill([
            'status' => KlaviyoCampaignSweep::STATUS_COMPLETED,
            'swept_at' => now(),
        ])->save();

        Log::channel('push')->info('Campaign sweep completed', [
            'campaign_id' => $sweep->campaign_id,
            'recipients_found' => $recipients,
            'pushes_dispatched' => $dispatched,
        ]);
    }

    public function failed(Throwable $e): void
    {
        $sweep = KlaviyoCampaignSweep::find($this->sweepId);
        $sweep?->markFailed($e->getMessage());
    }
}
