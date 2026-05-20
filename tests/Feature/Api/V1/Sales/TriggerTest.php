<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Sales;

use App\Jobs\AI\StoreAnalyticsJob;
use App\Models\TriggerRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase A — proactive trigger endpoint coverage.
 *
 * The endpoint must:
 *   - return the highest-priority active rule for the shop+page
 *   - respect the per-session dedupe flag
 *   - return has_trigger=false rather than 4xx when nothing matches
 *   - validate page_type + session_id
 */
class TriggerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        // Trigger dedupe uses the cache — keep it deterministic per test.
        Cache::flush();
    }

    public function test_show_returns_top_priority_active_rule(): void
    {
        TriggerRule::factory()
            ->forShop('demo.myshopify.com')
            ->forPage(TriggerRule::PAGE_PRODUCT)
            ->exitIntent()
            ->priority(20)
            ->create(['message_template' => 'Low priority']);

        $top = TriggerRule::factory()
            ->forShop('demo.myshopify.com')
            ->forPage(TriggerRule::PAGE_PRODUCT)
            ->exitIntent()
            ->priority(5)
            ->create(['message_template' => 'High priority message']);

        $response = $this->getJson('/api/v1/ai/triggers/demo.myshopify.com?page_type=product&session_id=sess-1');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.has_trigger', true);
        $response->assertJsonPath('data.trigger_id', $top->id);
        $response->assertJsonPath('data.trigger_type', TriggerRule::TYPE_EXIT_INTENT);
        $response->assertJsonPath('data.message', 'High priority message');
        $response->assertJsonPath('data.delay_ms', 0);
    }

    public function test_show_matches_page_all_when_specific_page_has_no_rule(): void
    {
        $rule = TriggerRule::factory()
            ->forShop('demo.myshopify.com')
            ->forPage(TriggerRule::PAGE_ALL)
            ->exitIntent()
            ->create(['message_template' => 'Universal']);

        $response = $this->getJson('/api/v1/ai/triggers/demo.myshopify.com?page_type=collection&session_id=s2');

        $response->assertOk();
        $response->assertJsonPath('data.has_trigger', true);
        $response->assertJsonPath('data.trigger_id', $rule->id);
    }

    public function test_show_returns_has_trigger_false_when_no_match(): void
    {
        TriggerRule::factory()
            ->forShop('other.myshopify.com')
            ->forPage(TriggerRule::PAGE_PRODUCT)
            ->create();

        $response = $this->getJson('/api/v1/ai/triggers/demo.myshopify.com?page_type=product&session_id=s3');

        $response->assertOk();
        $response->assertJsonPath('data.has_trigger', false);
    }

    public function test_show_skips_inactive_rules(): void
    {
        TriggerRule::factory()
            ->forShop('demo.myshopify.com')
            ->forPage(TriggerRule::PAGE_PRODUCT)
            ->inactive()
            ->create();

        $response = $this->getJson('/api/v1/ai/triggers/demo.myshopify.com?page_type=product&session_id=s4');

        $response->assertOk();
        $response->assertJsonPath('data.has_trigger', false);
    }

    public function test_show_respects_already_fired_flag(): void
    {
        $rule = TriggerRule::factory()
            ->forShop('demo.myshopify.com')
            ->forPage(TriggerRule::PAGE_PRODUCT)
            ->exitIntent()
            ->create();

        $first = $this->getJson('/api/v1/ai/triggers/demo.myshopify.com?page_type=product&session_id=sess-dedupe');
        $first->assertOk();
        $first->assertJsonPath('data.has_trigger', true);
        $first->assertJsonPath('data.trigger_id', $rule->id);

        $second = $this->getJson('/api/v1/ai/triggers/demo.myshopify.com?page_type=product&session_id=sess-dedupe');
        $second->assertOk();
        $second->assertJsonPath('data.has_trigger', false);
    }

    public function test_show_emits_delay_ms_for_time_on_page_rule(): void
    {
        TriggerRule::factory()
            ->forShop('demo.myshopify.com')
            ->forPage(TriggerRule::PAGE_HOME)
            ->timeOnPage(15)
            ->create();

        $response = $this->getJson('/api/v1/ai/triggers/demo.myshopify.com?page_type=home&session_id=sess-tp');

        $response->assertOk();
        $response->assertJsonPath('data.has_trigger', true);
        $response->assertJsonPath('data.trigger_type', TriggerRule::TYPE_TIME_ON_PAGE);
        $response->assertJsonPath('data.delay_ms', 15000);
    }

    public function test_show_interpolates_message_template_with_query_context(): void
    {
        TriggerRule::factory()
            ->forShop('demo.myshopify.com')
            ->forPage(TriggerRule::PAGE_PRODUCT)
            ->exitIntent()
            ->create(['message_template' => 'Need help with {product_title}?']);

        $response = $this->getJson('/api/v1/ai/triggers/demo.myshopify.com?'
            .'page_type=product&session_id=sess-tok&product_title=Acme%20Widget');

        $response->assertOk();
        $response->assertJsonPath('data.message', 'Need help with Acme Widget?');
    }

    public function test_show_rejects_invalid_page_type(): void
    {
        $response = $this->getJson('/api/v1/ai/triggers/demo.myshopify.com?page_type=invalid&session_id=s5');

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_show_rejects_missing_session_id(): void
    {
        $response = $this->getJson('/api/v1/ai/triggers/demo.myshopify.com?page_type=product');

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    // ----------------------------------------------------------------------
    // POST /event coverage — must always return 200 and dispatch async.
    // ----------------------------------------------------------------------

    public function test_record_event_returns_200_and_dispatches_analytics_job(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/v1/ai/triggers/event', [
            'session_id' => 'sess-evt-1',
            'shop_domain' => 'demo.myshopify.com',
            'event' => 'trigger_opened',
            'trigger_type' => 'exit_intent',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.accepted', true);

        Queue::assertPushed(
            StoreAnalyticsJob::class,
            fn (StoreAnalyticsJob $job): bool => $job->event === 'trigger_opened'
                && $job->sessionId === 'sess-evt-1'
                && ($job->payload['shop_domain'] ?? null) === 'demo.myshopify.com'
                && ($job->payload['trigger_type'] ?? null) === 'exit_intent'
        );
    }

    public function test_record_event_accepts_trigger_dismissed(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/v1/ai/triggers/event', [
            'session_id' => 'sess-evt-2',
            'shop_domain' => 'demo.myshopify.com',
            'event' => 'trigger_dismissed',
        ]);

        $response->assertOk();
        Queue::assertPushed(
            StoreAnalyticsJob::class,
            fn (StoreAnalyticsJob $job): bool => $job->event === 'trigger_dismissed'
        );
    }

    public function test_record_event_rejects_unknown_event_value(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/v1/ai/triggers/event', [
            'session_id' => 'sess-evt-3',
            'shop_domain' => 'demo.myshopify.com',
            'event' => 'unknown_event_type',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);

        Queue::assertNotPushed(StoreAnalyticsJob::class);
    }

    public function test_record_event_rejects_missing_required_fields(): void
    {
        $response = $this->postJson('/api/v1/ai/triggers/event', [
            'event' => 'trigger_opened',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }
}
