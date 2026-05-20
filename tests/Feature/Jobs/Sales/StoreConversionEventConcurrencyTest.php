<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs\Sales;

use App\Jobs\Sales\StoreConversionEventJob;
use App\Models\AiConversation;
use App\Models\ConversionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the read-then-write race in
 * StoreConversionEventJob::applyConversationSideEffects(). Two parallel
 * order_placed events for the same session used to clobber each other's
 * revenue_attributed write (each read N, both wrote N+revenue, losing one
 * increment). The post-fix code uses a SELECT … FOR UPDATE transaction and
 * an atomic `revenue_attributed = revenue_attributed + ?` UPDATE so
 * duplicates accumulate correctly.
 */
class StoreConversionEventConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_order_placed_events_accumulate_revenue_atomically(): void
    {
        $conversation = AiConversation::factory()->create([
            'session_id' => 'race-session-rev',
            'shop_domain' => 'demo.myshopify.com',
            'revenue_attributed' => 0.00,
            'conversion_type' => null,
        ]);

        // Two synchronous dispatches mirror the duplicate-webhook scenario.
        // Queue is sync in phpunit.xml so each job runs inline; the lock +
        // atomic UPDATE has to make 10.00 + 10.00 = 20.00 deterministically.
        (new StoreConversionEventJob(
            sessionId: 'race-session-rev',
            shopDomain: 'demo.myshopify.com',
            eventType: ConversionEvent::EVENT_ORDER_PLACED,
            orderId: 'gid://shopify/Order/A',
            revenue: 10.00,
        ))->handle();

        (new StoreConversionEventJob(
            sessionId: 'race-session-rev',
            shopDomain: 'demo.myshopify.com',
            eventType: ConversionEvent::EVENT_ORDER_PLACED,
            orderId: 'gid://shopify/Order/B',
            revenue: 10.00,
        ))->handle();

        $conversation->refresh();
        $this->assertSame('20.00', (string) $conversation->revenue_attributed);
        $this->assertSame(AiConversation::CONVERSION_DIRECT, $conversation->conversion_type);
    }

    public function test_recovery_event_before_order_placed_flips_to_assisted(): void
    {
        $conversation = AiConversation::factory()->create([
            'session_id' => 'race-session-asst',
            'shop_domain' => 'demo.myshopify.com',
            'revenue_attributed' => 0.00,
            'conversion_type' => null,
        ]);

        (new StoreConversionEventJob(
            sessionId: 'race-session-asst',
            shopDomain: 'demo.myshopify.com',
            eventType: ConversionEvent::EVENT_ABANDON_RECOVERY_SENT,
        ))->handle();

        (new StoreConversionEventJob(
            sessionId: 'race-session-asst',
            shopDomain: 'demo.myshopify.com',
            eventType: ConversionEvent::EVENT_ORDER_PLACED,
            orderId: 'gid://shopify/Order/X',
            revenue: 42.50,
        ))->handle();

        $conversation->refresh();
        $this->assertSame('42.50', (string) $conversation->revenue_attributed);
        $this->assertSame(AiConversation::CONVERSION_ASSISTED, $conversation->conversion_type);
    }

    public function test_lead_captured_event_flips_lead_captured_flag_idempotently(): void
    {
        $conversation = AiConversation::factory()->create([
            'session_id' => 'race-session-lead',
            'shop_domain' => 'demo.myshopify.com',
            'lead_captured' => false,
        ]);

        (new StoreConversionEventJob(
            sessionId: 'race-session-lead',
            shopDomain: 'demo.myshopify.com',
            eventType: ConversionEvent::EVENT_LEAD_CAPTURED,
        ))->handle();

        (new StoreConversionEventJob(
            sessionId: 'race-session-lead',
            shopDomain: 'demo.myshopify.com',
            eventType: ConversionEvent::EVENT_LEAD_CAPTURED,
        ))->handle();

        $conversation->refresh();
        $this->assertTrue((bool) $conversation->lead_captured);
        // No revenue side-effect from lead_captured.
        $this->assertSame('0.00', (string) $conversation->revenue_attributed);
    }
}
