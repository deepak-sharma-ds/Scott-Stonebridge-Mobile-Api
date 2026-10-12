<?php

namespace Tests\Feature\Console;

use App\Jobs\Campaign\SendCampaignEmailJob;
use App\Jobs\EmailReading\SendEmailReadingJob;
use App\Models\CampaignDelivery;
use App\Models\CampaignProduct;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Models\MarketingCampaign;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchDueDeliveriesCommandsTest extends TestCase
{
    use RefreshDatabase;

    private function readingDelivery(array $overrides = []): EmailReadingDelivery
    {
        static $lineItem = 5000;

        $product = EmailReadingProduct::firstOrCreate(['shopify_product_id' => 111], [
            'name' => 'Love', 'slug' => 'love', 'questions_schema' => [], 'prompt_template' => 'p',
            'email_subject' => 's', 'is_active' => true, 'is_automation_enabled' => true,
        ]);

        return EmailReadingDelivery::create(array_merge([
            'shopify_order_id' => 1, 'shopify_line_item_id' => $lineItem++, 'email_reading_product_id' => $product->id,
            'customer_email' => 'a@example.com', 'questions' => [], 'ai_response' => 'text',
            'status' => EmailReadingDelivery::STATUS_GENERATED, 'scheduled_at' => now()->subMinute(),
        ], $overrides));
    }

    private function campaignDelivery(array $overrides = []): CampaignDelivery
    {
        static $lineItem = 6000;

        $campaign = MarketingCampaign::firstOrCreate(['campaign_key' => 'k'], ['name' => 'K', 'status' => 'active']);
        $product = CampaignProduct::firstOrCreate(['marketing_campaign_id' => $campaign->id, 'shopify_product_id' => 111], ['shopify_variant_id' => 1]);

        return CampaignDelivery::create(array_merge([
            'shopify_order_id' => 1, 'shopify_line_item_id' => $lineItem++, 'campaign_product_id' => $product->id,
            'customer_email' => 'a@example.com', 'status' => CampaignDelivery::STATUS_PENDING, 'scheduled_at' => now()->subMinute(),
        ], $overrides));
    }

    public function test_the_reading_dispatcher_claims_only_due_deliveries(): void
    {
        Queue::fake();
        $due = $this->readingDelivery();
        $future = $this->readingDelivery(['scheduled_at' => now()->addDay()]);
        $immediate = $this->readingDelivery(['scheduled_at' => null]);

        $this->artisan('readings:dispatch-due')->assertSuccessful();

        $this->assertSame(EmailReadingDelivery::STATUS_SENDING, $due->fresh()->status);
        $this->assertSame(EmailReadingDelivery::STATUS_SENDING, $immediate->fresh()->status);
        $this->assertSame(EmailReadingDelivery::STATUS_GENERATED, $future->fresh()->status);
        Queue::assertPushed(SendEmailReadingJob::class, 2);

        // A second run claims nothing more: a claimed delivery is never dispatched twice.
        $this->artisan('readings:dispatch-due')->assertSuccessful();
        Queue::assertPushed(SendEmailReadingJob::class, 2);
    }

    public function test_the_campaign_dispatcher_claims_only_due_deliveries_and_respects_the_batch_size(): void
    {
        Queue::fake();
        config(['campaign_email.dispatch.batch_size' => 1]);
        $this->campaignDelivery();
        $this->campaignDelivery();
        $this->campaignDelivery(['scheduled_at' => now()->addDay()]);

        $this->artisan('campaign-emails:dispatch-due')->assertSuccessful();
        Queue::assertPushed(SendCampaignEmailJob::class, 1);

        $this->artisan('campaign-emails:dispatch-due')->assertSuccessful();
        Queue::assertPushed(SendCampaignEmailJob::class, 2);
    }

    public function test_the_dispatchers_recover_stuck_sends(): void
    {
        Queue::fake();
        $stuck = $this->readingDelivery(['status' => EmailReadingDelivery::STATUS_SENDING, 'claim_token' => 't', 'claimed_at' => now()->subHour()]);

        $this->artisan('readings:dispatch-due')->assertSuccessful();

        // Released once, then re-claimed under a fresh token in the same run.
        $this->assertSame(1, $stuck->fresh()->release_count);
        $this->assertNotSame('t', $stuck->fresh()->claim_token);
    }

    public function test_the_health_check_alerts_only_when_due_deliveries_are_not_being_dispatched(): void
    {
        $this->artisan('email-automation:health')->assertSuccessful();

        $this->readingDelivery(['scheduled_at' => now()->subMinutes(30)]);

        $this->artisan('email-automation:health')->assertFailed();
    }

    public function test_the_dispatchers_are_scheduled_every_minute(): void
    {
        $events = collect(app(Schedule::class)->events());

        foreach (['readings:dispatch-due', 'campaign-emails:dispatch-due'] as $command) {
            $event = $events->first(fn ($e) => str_contains($e->command, $command));
            $this->assertNotNull($event, "{$command} is not scheduled");
            $this->assertSame('* * * * *', $event->expression);
        }
    }
}
