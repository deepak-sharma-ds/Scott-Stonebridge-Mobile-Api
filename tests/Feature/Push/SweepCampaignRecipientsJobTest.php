<?php

declare(strict_types=1);

namespace Tests\Feature\Push;

use App\Contracts\Klaviyo\KlaviyoApiClientInterface;
use App\Jobs\Push\SendPushToRecipientJob;
use App\Jobs\Push\SweepCampaignRecipientsJob;
use App\Models\KlaviyoCampaignSweep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SweepCampaignRecipientsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'push.enabled' => true,
            'push.sweep.enabled' => true,
            'push.sweep.page_size' => 200,
            'push.klaviyo.api_key' => 'pk_test',
            'push.klaviyo.revision' => '2025-04-15',
            'push.klaviyo.received_email_metric_id' => 'METRIC1',
            'push.test_emails' => [],
        ]);
    }

    protected function eventPage(array $emails, ?string $nextCursor): array
    {
        $data = [];
        $included = [];
        foreach ($emails as $i => $email) {
            $profileId = 'P'.$i.'_'.md5($email);
            $data[] = [
                'id' => 'E'.$i,
                'attributes' => ['event_properties' => []],
                'relationships' => ['profile' => ['data' => ['id' => $profileId]]],
            ];
            $included[] = [
                'type' => 'profile',
                'id' => $profileId,
                'attributes' => ['email' => $email],
            ];
        }

        $body = ['data' => $data, 'included' => $included, 'links' => []];
        if ($nextCursor !== null) {
            $body['links']['next'] = 'https://a.klaviyo.com/api/events?page%5Bcursor%5D='.$nextCursor;
        }

        return $body;
    }

    public function test_pages_events_and_dispatches_push_per_recipient(): void
    {
        Queue::fake();

        Http::fake([
            'a.klaviyo.com/api/events*' => Http::sequence()
                ->push($this->eventPage(['a@example.com', 'b@example.com'], 'CUR2'))
                ->push($this->eventPage(['c@example.com'], null)),
        ]);

        $sweep = KlaviyoCampaignSweep::factory()->create([
            'campaign_id' => 'CAMP1',
            'status' => KlaviyoCampaignSweep::STATUS_SWEEPING,
        ]);

        (new SweepCampaignRecipientsJob($sweep->id))->handle(app(KlaviyoApiClientInterface::class));

        Queue::assertPushed(SendPushToRecipientJob::class, 3);

        $sweep->refresh();
        $this->assertSame(KlaviyoCampaignSweep::STATUS_COMPLETED, $sweep->status);
        $this->assertSame(3, $sweep->recipients_found);
        $this->assertSame(3, $sweep->pushes_dispatched);
    }

    public function test_completed_sweep_is_not_reprocessed(): void
    {
        Queue::fake();
        Http::fake();

        $sweep = KlaviyoCampaignSweep::factory()->completed()->create(['campaign_id' => 'CAMP9']);

        (new SweepCampaignRecipientsJob($sweep->id))->handle(app(KlaviyoApiClientInterface::class));

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }
}
