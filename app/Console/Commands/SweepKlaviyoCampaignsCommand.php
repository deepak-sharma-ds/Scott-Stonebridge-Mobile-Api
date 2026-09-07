<?php

namespace App\Console\Commands;

use App\Contracts\Klaviyo\KlaviyoApiClientInterface;
use App\Jobs\Push\SweepCampaignRecipientsJob;
use App\Models\KlaviyoCampaignSweep;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Discovers newly SENT Klaviyo email campaigns and fans out per-recipient
 * pushes. Klaviyo has no campaign-sent webhook on the standard plan, so this
 * polls the Campaigns API and hands each new campaign to a background sweep
 * job that pages the Events API for recipients.
 *
 * Campaigns whose send_time predates the lookback window (i.e. sent before
 * this feature was deployed) are seeded as completed so they never push
 * retroactively.
 */
class SweepKlaviyoCampaignsCommand extends Command
{
    protected $signature = 'push:sweep-klaviyo-campaigns';

    protected $description = 'Detect newly sent Klaviyo campaigns and dispatch marketing push notifications';

    public function handle(KlaviyoApiClientInterface $klaviyo): int
    {
        if (! config('push.enabled', false) || ! config('push.sweep.enabled', false)) {
            $this->info('Push sweep disabled.');

            return self::SUCCESS;
        }

        $lookbackHours = (int) config('push.sweep.lookback_hours', 24);
        $settleMinutes = (int) config('push.sweep.settle_minutes', 15);

        $since = Carbon::now()->subHours($lookbackHours);
        $campaigns = $klaviyo->getRecentlySentCampaigns($since);

        $dispatched = 0;

        foreach ($campaigns as $campaign) {
            $sendTime = $campaign['send_time'] ? Carbon::parse($campaign['send_time']) : null;

            $sweep = KlaviyoCampaignSweep::firstOrCreate(
                ['campaign_id' => $campaign['campaign_id']],
                [
                    'campaign_name' => $campaign['campaign_name'],
                    // Real campaign copy when Klaviyo has it, else the
                    // generic default — resolved once here so every
                    // recipient of this campaign gets identical content.
                    'title' => ($campaign['subject'] ?: null) ?? config('push.defaults.title'),
                    'body' => ($campaign['preview_text'] ?: null) ?? config('push.defaults.body'),
                    // Full HTML template (design intact) for the in-app
                    // notification detail screen; the push banner itself
                    // only ever uses title/body (kept short for the OS tray).
                    'content' => $campaign['content'] ?: null,
                    'send_time' => $sendTime,
                    // Campaigns older than the lookback window predate the
                    // feature; mark them completed so they never push.
                    'status' => ($sendTime && $sendTime->lt($since))
                        ? KlaviyoCampaignSweep::STATUS_COMPLETED
                        : KlaviyoCampaignSweep::STATUS_PENDING,
                    'swept_at' => ($sendTime && $sendTime->lt($since)) ? Carbon::now() : null,
                ]
            );

            if ($sweep->status !== KlaviyoCampaignSweep::STATUS_PENDING) {
                continue;
            }

            // Wait for Klaviyo's asynchronous events to populate before sweeping.
            if ($sweep->send_time && $sweep->send_time->gt(Carbon::now()->subMinutes($settleMinutes))) {
                continue;
            }

            $sweep->forceFill(['status' => KlaviyoCampaignSweep::STATUS_SWEEPING])->save();

            SweepCampaignRecipientsJob::dispatch($sweep->id)
                ->onConnection(config('push.queue.connection'))
                ->onQueue(config('push.queue.fanout'));

            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} campaign sweep(s).");

        return self::SUCCESS;
    }
}
