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

    protected function fakeCampaigns(array $campaigns, array $included = [], array $templates = []): void
    {
        $fakes = [
            'a.klaviyo.com/api/campaigns*' => Http::response(['data' => $campaigns, 'included' => $included], 200),
        ];

        foreach ($templates as $templateId => $text) {
            $fakes["a.klaviyo.com/api/templates/{$templateId}*"] = Http::response(
                ['data' => ['type' => 'template', 'id' => $templateId, 'attributes' => ['text' => $text]]],
                200
            );
        }

        Http::fake($fakes);
    }

    protected function campaign(string $id, string $status, Carbon $sendTime, ?string $messageId = null): array
    {
        return [
            'id' => $id,
            'attributes' => [
                'name' => 'Campaign '.$id,
                'status' => $status,
                'send_time' => $sendTime->toIso8601String(),
            ],
            'relationships' => $messageId ? [
                'campaign-messages' => ['data' => [['type' => 'campaign-message', 'id' => $messageId]]],
            ] : [],
        ];
    }

    protected function campaignMessage(string $id, string $subject, string $previewText, ?string $templateId = null): array
    {
        return [
            'type' => 'campaign-message',
            'id' => $id,
            'attributes' => ['definition' => ['content' => ['subject' => $subject, 'preview_text' => $previewText]]],
            'relationships' => $templateId ? [
                'template' => ['data' => ['type' => 'template', 'id' => $templateId]],
            ] : [],
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

    public function test_sweep_persists_real_campaign_content_when_available(): void
    {
        Queue::fake();
        $this->fakeCampaigns(
            [$this->campaign('CAMP3', 'Sent', Carbon::now()->subMinutes(30), 'MSG3')],
            [$this->campaignMessage('MSG3', 'Real subject line', 'Real preview text')]
        );

        $this->artisan('push:sweep-klaviyo-campaigns')->assertExitCode(0);

        $sweep = KlaviyoCampaignSweep::where('campaign_id', 'CAMP3')->first();
        $this->assertSame('Real subject line', $sweep->title);
        $this->assertSame('Real preview text', $sweep->body);
    }

    public function test_sweep_falls_back_to_default_copy_without_campaign_message_content(): void
    {
        config(['push.defaults.title' => 'Default Title', 'push.defaults.body' => 'Default Body']);
        Queue::fake();
        $this->fakeCampaigns([
            $this->campaign('CAMP4', 'Sent', Carbon::now()->subMinutes(30)),
        ]);

        $this->artisan('push:sweep-klaviyo-campaigns')->assertExitCode(0);

        $sweep = KlaviyoCampaignSweep::where('campaign_id', 'CAMP4')->first();
        $this->assertSame('Default Title', $sweep->title);
        $this->assertSame('Default Body', $sweep->body);
    }

    public function test_sweep_derives_excerpt_from_template_when_preview_text_duplicates_subject(): void
    {
        Queue::fake();
        $this->fakeCampaigns(
            [$this->campaign('CAMP5', 'Sent', Carbon::now()->subMinutes(30), 'MSG5')],
            [$this->campaignMessage('MSG5', 'QA Testing Push Notification', 'QA Testing Push Notification', 'TPL5')],
            ['TPL5' => "[Logo](http://example.com)\n\nQA Testing Push Notification\n\n\u{00A0}\n\nHi {{ person.first_name|default:'there' }},\n\u{00A0}\nI just wanted to send a gentle reminder that your 20% welcome gift is still here for you.\n\n[Redeem now](http://example.com)"]
        );

        $this->artisan('push:sweep-klaviyo-campaigns')->assertExitCode(0);

        $sweep = KlaviyoCampaignSweep::where('campaign_id', 'CAMP5')->first();
        $this->assertSame('QA Testing Push Notification', $sweep->title);
        $this->assertSame('I just wanted to send a gentle reminder that your 20% welcome gift is still here for you.', $sweep->body);
        $this->assertStringContainsString('gentle reminder that your 20% welcome gift', $sweep->content);
        // Liquid tags never resolve on a generic template fetch — must be stripped, not shown raw.
        $this->assertStringNotContainsString('{{', $sweep->content);
    }

    public function test_sweep_strips_both_liquid_tag_styles_from_full_content(): void
    {
        Queue::fake();
        $this->fakeCampaigns(
            [$this->campaign('CAMP7', 'Sent', Carbon::now()->subMinutes(30), 'MSG7')],
            [$this->campaignMessage('MSG7', 'Subject', 'Subject', 'TPL7')],
            ['TPL7' => "Real message body here.\n\nUnsubscribe: [Unsubscribe]({% unsubscribe_link %})."]
        );

        $this->artisan('push:sweep-klaviyo-campaigns')->assertExitCode(0);

        $sweep = KlaviyoCampaignSweep::where('campaign_id', 'CAMP7')->first();
        $this->assertStringNotContainsString('{%', $sweep->content);
        $this->assertStringNotContainsString('%}', $sweep->content);
    }

    public function test_sweep_keeps_meaningful_preview_text_but_still_stores_full_content(): void
    {
        Queue::fake();
        $this->fakeCampaigns(
            [$this->campaign('CAMP6', 'Sent', Carbon::now()->subMinutes(30), 'MSG6')],
            [$this->campaignMessage('MSG6', 'QA Testing Push Notification', 'Your 20% off code is waiting', 'TPL6')],
            ['TPL6' => "[Logo](http://example.com)\n\nQA Testing Push Notification\n\nHere is the full email body, in full.\n\n[Redeem now](http://example.com)"]
        );

        $this->artisan('push:sweep-klaviyo-campaigns')->assertExitCode(0);

        $sweep = KlaviyoCampaignSweep::where('campaign_id', 'CAMP6')->first();
        // Marketer's real preview_text is respected for the push body...
        $this->assertSame('Your 20% off code is waiting', $sweep->body);
        // ...but the full template is still fetched and stored for the
        // in-app notification detail screen.
        $this->assertStringContainsString('Here is the full email body, in full.', $sweep->content);
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
