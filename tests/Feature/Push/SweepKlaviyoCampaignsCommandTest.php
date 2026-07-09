<?php

declare(strict_types=1);

namespace Tests\Feature\Push;

use App\Jobs\Push\SweepCampaignRecipientsJob;
use App\Models\KlaviyoCampaignSweep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SweepKlaviyoCampaignsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'push.enabled' => true,
            'push.sweep.enabled' => true,
            'push.sweep.lookback_hours' => 24,
            'push.sweep.settle_minutes' => 15,
            'push.klaviyo.api_key' => 'pk_test',
            'push.klaviyo.revision' => '2025-04-15',
            'push.klaviyo.received_email_metric_id' => 'METRIC1',
        ]);
    }

    protected function fakeCampaigns(array $campaigns): void
    {
        Http::fake([
            'a.klaviyo.com/api/campaigns*' => Http::response(['data' => $campaigns], 200),
        ]);
    }

    protected function campaign(string $id, string $status, Carbon $sendTime): array
    {
        return [
            'id' => $id,
            'attributes' => [
                'name' => 'Campaign '.$id,
                'status' => $status,
                'send_time' => $sendTime->toIso8601String(),
            ],
        ];
    }

    public function test_new_sent_campaign_creates_sweep_and_dispatches_job(): void
    {
        Queue::fake();
        $this->fakeCampaigns([
            $this->campaign('CAMP1', 'Sent', Carbon::now()->subMinutes(30)),
        ]);

        $this->artisan('push:sweep-klaviyo-campaigns')->assertExitCode(0);

        $sweep = KlaviyoCampaignSweep::where('campaign_id', 'CAMP1')->first();
        $this->assertNotNull($sweep);
        $this->assertSame(KlaviyoCampaignSweep::STATUS_SWEEPING, $sweep->status);
        Queue::assertPushed(SweepCampaignRecipientsJob::class);
    }

    public function test_campaign_within_settle_window_is_not_swept_yet(): void
    {
        Queue::fake();
        $this->fakeCampaigns([
            $this->campaign('CAMP2', 'Sent', Carbon::now()->subMinutes(5)),
        ]);

        $this->artisan('push:sweep-klaviyo-campaigns')->assertExitCode(0);

        $this->assertSame(
            KlaviyoCampaignSweep::STATUS_PENDING,
            KlaviyoCampaignSweep::where('campaign_id', 'CAMP2')->value('status')
        );
        Queue::assertNothingPushed();
    }

    public function test_predeployment_campaign_seeded_completed(): void
    {
        Queue::fake();
        // Older than the 24h lookback window => sent before feature existed.
        $this->fakeCampaigns([
            $this->campaign('OLD1', 'Sent', Carbon::now()->subHours(48)),
        ]);

        $this->artisan('push:sweep-klaviyo-campaigns')->assertExitCode(0);

        $this->assertSame(
            KlaviyoCampaignSweep::STATUS_COMPLETED,
            KlaviyoCampaignSweep::where('campaign_id', 'OLD1')->value('status')
        );
        Queue::assertNothingPushed();
    }

    public function test_disabled_sweep_is_inert(): void
    {
        config(['push.sweep.enabled' => false]);
        Queue::fake();
        Http::fake();

        $this->artisan('push:sweep-klaviyo-campaigns')->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertSame(0, KlaviyoCampaignSweep::count());
    }
}
