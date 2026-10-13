<?php

namespace Tests\Feature\Webhook;

use App\Jobs\EmailReading\NotifyReadingFailureJob;
use App\Jobs\EmailReading\ProcessEmailReadingOrderJob;
use App\Jobs\Shopify\ProcessShopifyWebhookEventJob;
use App\Models\CampaignDelivery;
use App\Models\CampaignProduct;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Models\MarketingCampaign;
use App\Models\ShopifyWebhookEvent;
use App\Models\UnlistedProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WebhookInboxTest extends TestCase
{
    use RefreshDatabase;

    private function readingProduct(int $productId = 111): EmailReadingProduct
    {
        return EmailReadingProduct::create([
            'shopify_product_id' => $productId,
            'name' => 'Love Reading',
            'slug' => 'love-reading-'.$productId,
            'questions_schema' => [],
            'prompt_template' => 'Reading for {{ $customer_name }}',
            'email_subject' => 'Your reading',
            'is_active' => true,
            'is_automation_enabled' => true,
        ]);
    }

    /**
     * @param  array<int,array<string,mixed>>  $lines
     * @param  array<int,array<string,mixed>>  $shipping
     * @return array<string,mixed>
     */
    private function order(array $lines, int $orderId = 9001, array $shipping = []): array
    {
        return [
            'id' => $orderId,
            'email' => 'buyer@example.com',
            'customer' => ['first_name' => 'Jane', 'last_name' => 'Doe'],
            'line_items' => $lines,
            'shipping_lines' => $shipping,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function line(int $productId, int $lineItemId): array
    {
        return ['id' => $lineItemId, 'product_id' => $productId, 'properties' => []];
    }

    // ---- inbox mechanics ----

    public function test_an_event_is_stored_then_processed_with_a_recorded_outcome(): void
    {
        Queue::fake([ProcessEmailReadingOrderJob::class]);
        $this->readingProduct();

        $this->postJson(route('webhook.shopify.reading'), $this->order([$this->line(111, 501)]), ['X-Shopify-Webhook-Id' => 'wh-1'])
            ->assertOk();

        $event = ShopifyWebhookEvent::firstOrFail();
        $this->assertSame('reading.paid', $event->endpoint);
        $this->assertSame(ShopifyWebhookEvent::STATUS_PROCESSED, $event->status);
        $this->assertSame(1, $event->attempts);
        $this->assertNotNull($event->processed_at);

        $this->assertDatabaseHas('email_reading_deliveries', ['shopify_line_item_id' => 501, 'status' => 'pending']);
        Queue::assertPushed(ProcessEmailReadingOrderJob::class, 1);
    }

    public function test_the_http_request_only_stores_and_queues(): void
    {
        Queue::fake();
        $this->readingProduct();

        $this->postJson(route('webhook.shopify.reading'), $this->order([$this->line(111, 501)]))->assertOk();

        Queue::assertPushed(ProcessShopifyWebhookEventJob::class, 1);
        $this->assertDatabaseCount('email_reading_deliveries', 0);
        $this->assertSame(ShopifyWebhookEvent::STATUS_RECEIVED, ShopifyWebhookEvent::firstOrFail()->status);
    }

    public function test_duplicate_webhook_id_on_the_same_endpoint_is_stored_once(): void
    {
        Queue::fake([ProcessEmailReadingOrderJob::class]);
        $this->readingProduct();
        $headers = ['X-Shopify-Webhook-Id' => 'wh-dup'];

        $this->postJson(route('webhook.shopify.reading'), $this->order([$this->line(111, 501)]), $headers)->assertOk();
        $this->postJson(route('webhook.shopify.reading'), $this->order([$this->line(111, 501)]), $headers)->assertOk();

        $this->assertSame(1, ShopifyWebhookEvent::count());
        $this->assertSame(1, EmailReadingDelivery::count());
    }

    public function test_the_same_webhook_id_on_different_endpoints_is_never_swallowed(): void
    {
        Queue::fake([ProcessEmailReadingOrderJob::class]);
        $this->readingProduct();
        $headers = ['X-Shopify-Webhook-Id' => 'shared-id'];

        $this->postJson(route('webhook.shopify.reading'), $this->order([$this->line(111, 501)]), $headers)->assertOk();
        $this->postJson(route('webhook.shopify.campaign'), $this->order([$this->line(111, 501)]), $headers)->assertOk();

        $this->assertSame(2, ShopifyWebhookEvent::count());
    }

    public function test_a_payload_without_an_order_id_is_recorded_as_ignored(): void
    {
        Queue::fake();

        $this->postJson(route('webhook.shopify.reading'), ['hello' => 'world'])->assertOk();

        $event = ShopifyWebhookEvent::firstOrFail();
        $this->assertSame(ShopifyWebhookEvent::STATUS_IGNORED, $event->status);
        $this->assertSame('no_order_id', $event->reason);
        Queue::assertNotPushed(ProcessShopifyWebhookEventJob::class);
    }

    public function test_a_failed_store_answers_500_so_shopify_retries(): void
    {
        Schema::drop('shopify_webhook_events');

        $this->postJson(route('webhook.shopify.reading'), $this->order([$this->line(111, 501)]))->assertStatus(500);
    }

    // ---- recorded outcomes ----

    public function test_orders_with_no_matching_product_are_recorded_as_ignored_with_a_reason(): void
    {
        $this->postJson(route('webhook.shopify.reading'), $this->order([$this->line(999, 501)]))->assertOk();

        $this->assertDatabaseHas('shopify_webhook_events', ['status' => 'ignored', 'reason' => 'no_matching_product']);
    }

    public function test_the_reading_test_allowlist_still_filters_but_now_records_the_skip(): void
    {
        Queue::fake([ProcessEmailReadingOrderJob::class]);
        config(['email_reading.test_emails' => ['tester@example.com']]);
        $this->readingProduct();

        $this->postJson(route('webhook.shopify.reading'), $this->order([$this->line(111, 501)]))->assertOk();

        $this->assertDatabaseHas('shopify_webhook_events', ['status' => 'ignored', 'reason' => 'not_in_test_allowlist']);
        $this->assertDatabaseCount('email_reading_deliveries', 0);
    }

    public function test_the_optional_campaign_allowlist_filters_campaign_orders(): void
    {
        config(['campaign_email.test_emails' => ['tester@example.com']]);

        $this->postJson(route('webhook.shopify.campaign'), $this->order([$this->line(111, 501)]))->assertOk();

        $this->assertDatabaseHas('shopify_webhook_events', ['endpoint' => 'campaign.paid', 'reason' => 'not_in_test_allowlist']);
    }

    // ---- failure, ordering race, replay ----

    public function test_same_day_upgrade_arriving_before_paid_is_retried_not_lost(): void
    {
        Queue::fake([ProcessEmailReadingOrderJob::class]);
        $this->readingProduct();
        $upgrade = [['title' => 'SAME DAY GUARANTEE - Via Email', 'is_removed' => false]];

        // orders/updated wins the race: no delivery exists yet.
        $this->postJson(route('webhook.shopify.reading.updated'), $this->order([$this->line(111, 501)], 9001, $upgrade))->assertOk();

        // (The sync test queue has no retries, so the event ends `failed`; on a
        // real queue it is released and retried with backoff before failing.)
        $updated = ShopifyWebhookEvent::where('endpoint', 'reading.updated')->firstOrFail();
        $this->assertSame(ShopifyWebhookEvent::STATUS_FAILED, $updated->status);
        $this->assertStringContainsString('has not been processed yet', (string) $updated->error);

        // orders/paid is then processed, creating the delivery.
        $this->postJson(route('webhook.shopify.reading'), $this->order([$this->line(111, 501)]))->assertOk();
        $this->assertSame(1, EmailReadingDelivery::count());

        $this->artisan('webhooks:retry', ['--id' => [$updated->id]])->assertSuccessful();

        $this->assertSame(ShopifyWebhookEvent::STATUS_PROCESSED, $updated->fresh()->status);
        $this->assertNotNull(EmailReadingDelivery::firstOrFail()->expedited_at);
    }

    public function test_a_failed_event_can_be_replayed_from_the_admin_page(): void
    {
        Queue::fake([ProcessEmailReadingOrderJob::class]);
        $this->readingProduct();
        $event = ShopifyWebhookEvent::create([
            'endpoint' => 'reading.paid',
            'topic' => 'orders/paid',
            'shopify_order_id' => 9001,
            'payload' => $this->order([$this->line(111, 501)]),
            'status' => ShopifyWebhookEvent::STATUS_FAILED,
            'error' => 'earlier outage',
            'received_at' => now(),
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('admin.webhook_events.replay', $event))
            ->assertSessionHas('success');

        $this->assertSame(ShopifyWebhookEvent::STATUS_PROCESSED, $event->fresh()->status);
        $this->assertSame(1, EmailReadingDelivery::count());
    }

    public function test_a_finished_event_cannot_be_replayed(): void
    {
        $event = ShopifyWebhookEvent::create([
            'endpoint' => 'reading.paid', 'topic' => 'orders/paid', 'shopify_order_id' => 1,
            'payload' => [], 'status' => ShopifyWebhookEvent::STATUS_PROCESSED, 'received_at' => now(),
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('admin.webhook_events.replay', $event))
            ->assertSessionHas('error');
    }

    public function test_the_stale_sweeper_requeues_stuck_events_but_leaves_failed_ones_alone(): void
    {
        Queue::fake();
        $stuck = ShopifyWebhookEvent::create([
            'endpoint' => 'reading.paid', 'topic' => 'orders/paid', 'shopify_order_id' => 1,
            'payload' => [], 'status' => ShopifyWebhookEvent::STATUS_PROCESSING, 'received_at' => now()->subHour(),
        ]);
        ShopifyWebhookEvent::whereKey($stuck->id)->update(['updated_at' => now()->subHour()]);
        $failed = ShopifyWebhookEvent::create([
            'endpoint' => 'reading.paid', 'topic' => 'orders/paid', 'shopify_order_id' => 2,
            'payload' => [], 'status' => ShopifyWebhookEvent::STATUS_FAILED, 'received_at' => now()->subHour(),
        ]);

        $this->artisan('webhooks:retry')->assertSuccessful();

        Queue::assertPushed(ProcessShopifyWebhookEventJob::class, fn ($job) => $job->eventId === $stuck->id);
        Queue::assertNotPushed(ProcessShopifyWebhookEventJob::class, fn ($job) => $job->eventId === $failed->id);

        $this->artisan('webhooks:retry', ['--failed' => true])->assertSuccessful();
        Queue::assertPushed(ProcessShopifyWebhookEventJob::class, fn ($job) => $job->eventId === $failed->id);
    }

    public function test_the_job_marks_an_event_failed_after_its_final_attempt(): void
    {
        $event = ShopifyWebhookEvent::create([
            'endpoint' => 'reading.paid', 'topic' => 'orders/paid', 'shopify_order_id' => 1,
            'payload' => [], 'status' => ShopifyWebhookEvent::STATUS_RECEIVED, 'received_at' => now(),
        ]);

        (new ProcessShopifyWebhookEventJob($event->id))->failed(new \RuntimeException('gave up'));

        $this->assertSame(ShopifyWebhookEvent::STATUS_FAILED, $event->fresh()->status);
        $this->assertSame('gave up', $event->fresh()->error);
    }

    // ---- vip / registry routing ----

    public function test_a_vip_product_in_the_reading_registry_is_refused_by_the_reading_flow_and_flagged(): void
    {
        Queue::fake([ProcessEmailReadingOrderJob::class, NotifyReadingFailureJob::class]);
        $this->readingProduct();
        UnlistedProduct::create(['shopify_product_id' => 111, 'title' => 'Vip', 'is_published' => true, 'is_vip' => true]);

        $this->postJson(route('webhook.shopify.reading'), $this->order([$this->line(111, 501)]))->assertOk();

        $this->assertDatabaseHas('email_reading_deliveries', ['shopify_line_item_id' => 501, 'status' => 'failed']);
        Queue::assertNotPushed(ProcessEmailReadingOrderJob::class);
        Queue::assertPushed(NotifyReadingFailureJob::class, 1);
    }

    public function test_a_campaign_registered_product_in_the_reading_registry_is_refused_by_the_reading_flow(): void
    {
        Queue::fake([ProcessEmailReadingOrderJob::class, NotifyReadingFailureJob::class]);
        $this->readingProduct();
        $campaign = MarketingCampaign::create(['campaign_key' => 'k', 'name' => 'K', 'status' => MarketingCampaign::STATUS_ACTIVE]);
        CampaignProduct::create(['marketing_campaign_id' => $campaign->id, 'shopify_product_id' => 111, 'shopify_variant_id' => 1]);

        $this->postJson(route('webhook.shopify.reading'), $this->order([$this->line(111, 501)]))->assertOk();

        $this->assertDatabaseHas('email_reading_deliveries', ['shopify_line_item_id' => 501, 'status' => 'failed']);
    }

    public function test_a_mixed_order_is_split_between_the_two_flows(): void
    {
        Queue::fake([ProcessEmailReadingOrderJob::class]);
        $this->readingProduct(111);
        UnlistedProduct::create(['shopify_product_id' => 222, 'title' => 'Vip', 'is_published' => true, 'is_vip' => true]);
        $campaign = MarketingCampaign::create(['campaign_key' => 'vip-key', 'name' => 'Vip', 'status' => MarketingCampaign::STATUS_ACTIVE]);
        $campaignProduct = CampaignProduct::create(['marketing_campaign_id' => $campaign->id, 'shopify_product_id' => 222, 'shopify_variant_id' => 2]);
        $campaignProduct->response()->create(['source' => 'manual', 'body' => 'Vip copy']);

        $vipLine = ['id' => 502, 'product_id' => 222, 'properties' => [['name' => '_campaign_key', 'value' => 'vip-key']]];
        $order = $this->order([$this->line(111, 501), $vipLine]);

        $this->postJson(route('webhook.shopify.reading'), $order)->assertOk();
        $this->postJson(route('webhook.shopify.campaign'), $order)->assertOk();

        $this->assertDatabaseHas('email_reading_deliveries', ['shopify_line_item_id' => 501, 'status' => 'pending']);
        $this->assertDatabaseMissing('email_reading_deliveries', ['shopify_line_item_id' => 502]);
        $this->assertDatabaseHas('campaign_deliveries', ['shopify_line_item_id' => 502, 'status' => 'pending']);
        $this->assertDatabaseMissing('campaign_deliveries', ['shopify_line_item_id' => 501]);
    }

    public function test_the_campaign_flow_refuses_a_product_that_is_not_vip_tagged(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'k', 'name' => 'K', 'status' => MarketingCampaign::STATUS_ACTIVE]);
        $cp = CampaignProduct::create(['marketing_campaign_id' => $campaign->id, 'shopify_product_id' => 222, 'shopify_variant_id' => 2]);
        $cp->response()->create(['source' => 'manual', 'body' => 'Copy']);
        UnlistedProduct::create(['shopify_product_id' => 222, 'title' => 'Not vip', 'is_published' => true, 'is_vip' => false]);

        $line = ['id' => 502, 'product_id' => 222, 'properties' => [['name' => '_campaign_key', 'value' => 'k']]];
        $this->postJson(route('webhook.shopify.campaign'), $this->order([$line]))->assertOk();

        $delivery = CampaignDelivery::where('shopify_line_item_id', 502)->firstOrFail();
        $this->assertSame(CampaignDelivery::STATUS_FAILED, $delivery->status);
        $this->assertStringContainsString('not vip-tagged', (string) $delivery->error_message);
    }

    public function test_the_campaign_flow_refuses_a_product_that_is_also_an_automation_enabled_reading_product(): void
    {
        $this->readingProduct(222);
        $campaign = MarketingCampaign::create(['campaign_key' => 'k', 'name' => 'K', 'status' => MarketingCampaign::STATUS_ACTIVE]);
        $cp = CampaignProduct::create(['marketing_campaign_id' => $campaign->id, 'shopify_product_id' => 222, 'shopify_variant_id' => 2]);
        $cp->response()->create(['source' => 'manual', 'body' => 'Copy']);
        UnlistedProduct::create(['shopify_product_id' => 222, 'title' => 'Vip', 'is_published' => true, 'is_vip' => true]);

        $line = ['id' => 502, 'product_id' => 222, 'properties' => [['name' => '_campaign_key', 'value' => 'k']]];
        $this->postJson(route('webhook.shopify.campaign'), $this->order([$line]))->assertOk();

        $delivery = CampaignDelivery::where('shopify_line_item_id', 502)->firstOrFail();
        $this->assertSame(CampaignDelivery::STATUS_FAILED, $delivery->status);
        $this->assertStringContainsString('both a Campaign Product and a Reading Product', (string) $delivery->error_message);
    }

    // ---- HMAC is optional and off by default for BOTH modules ----

    public function test_hmac_is_disabled_by_default_for_both_modules(): void
    {
        $this->assertFalse(config('email_reading.hmac_enabled'));
        $this->assertFalse(config('campaign_email.hmac_enabled'));

        $this->postJson(route('webhook.shopify.reading'), $this->order([$this->line(1, 1)]))->assertOk();
        $this->postJson(route('webhook.shopify.campaign'), $this->order([$this->line(1, 1)]))->assertOk();
    }

    public function test_each_module_can_enable_hmac_independently(): void
    {
        config(['campaign_email.hmac_enabled' => true, 'campaign_email.shopify_webhook_secret' => 'campaign-secret']);
        $body = json_encode($this->order([$this->line(1, 1)]));

        // Reading flow is untouched by the campaign flag.
        $this->call('POST', route('webhook.shopify.reading'), [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertOk();

        // Campaign flow now rejects an unsigned or wrongly-signed request...
        $this->call('POST', route('webhook.shopify.campaign'), [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertStatus(401);
        $this->call('POST', route('webhook.shopify.campaign'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, 'wrong', true)),
        ], $body)->assertStatus(401);

        // ...and accepts a correct signature, recording it as valid.
        $this->call('POST', route('webhook.shopify.campaign'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, 'campaign-secret', true)),
        ], $body)->assertOk();

        $this->assertTrue(ShopifyWebhookEvent::where('endpoint', 'campaign.paid')->firstOrFail()->hmac_valid);
    }
}
