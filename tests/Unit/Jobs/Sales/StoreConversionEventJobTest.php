<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Sales;

use App\Jobs\Sales\StoreConversionEventJob;
use App\Models\AiConversation;
use App\Models\ConversionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit coverage for StoreConversionEventJob.
 *
 *   - inserts row in conversion_events
 *   - order_placed updates revenue_attributed + conversion_type=direct
 *   - order_placed AFTER abandon_recovery_sent flips to assisted
 *   - lead_captured flips the cheap denormalised flag
 *   - empty inputs are a noop
 */
class StoreConversionEventJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_inserts_event_row(): void
    {
        $conversation = AiConversation::factory()->create();

        (new StoreConversionEventJob(
            sessionId: $conversation->session_id,
            shopDomain: $conversation->shop_domain,
            eventType: ConversionEvent::EVENT_PRODUCT_CLICKED,
            productId: 'gid://shopify/Product/9',
            metadata: ['source' => 'card'],
        ))->handle();

        $this->assertDatabaseHas('conversion_events', [
            'session_id' => $conversation->session_id,
            'event_type' => ConversionEvent::EVENT_PRODUCT_CLICKED,
            'product_id' => 'gid://shopify/Product/9',
        ]);
    }

    public function test_order_placed_marks_direct_and_attributes_revenue(): void
    {
        $conversation = AiConversation::factory()->create([
            'revenue_attributed' => 0,
        ]);

        (new StoreConversionEventJob(
            sessionId: $conversation->session_id,
            shopDomain: $conversation->shop_domain,
            eventType: ConversionEvent::EVENT_ORDER_PLACED,
            orderId: 'gid://shopify/Order/1',
            revenue: 49.99,
        ))->handle();

        $conversation->refresh();
        $this->assertSame('49.99', (string) $conversation->revenue_attributed);
        $this->assertSame(AiConversation::CONVERSION_DIRECT, $conversation->conversion_type);
    }

    public function test_order_placed_after_recovery_flips_to_assisted(): void
    {
        $conversation = AiConversation::factory()->create();

        // Prior recovery event
        ConversionEvent::factory()
            ->forSession($conversation->session_id)
            ->forShop($conversation->shop_domain)
            ->abandonRecoverySent()
            ->create();

        (new StoreConversionEventJob(
            sessionId: $conversation->session_id,
            shopDomain: $conversation->shop_domain,
            eventType: ConversionEvent::EVENT_ORDER_PLACED,
            orderId: 'gid://shopify/Order/2',
            revenue: 25.00,
        ))->handle();

        $this->assertSame(AiConversation::CONVERSION_ASSISTED, $conversation->fresh()->conversion_type);
    }

    public function test_lead_captured_sets_flag(): void
    {
        $conversation = AiConversation::factory()->create(['lead_captured' => false]);

        (new StoreConversionEventJob(
            sessionId: $conversation->session_id,
            shopDomain: $conversation->shop_domain,
            eventType: ConversionEvent::EVENT_LEAD_CAPTURED,
        ))->handle();

        $this->assertTrue($conversation->fresh()->lead_captured);
    }

    public function test_handle_is_noop_for_blank_inputs(): void
    {
        (new StoreConversionEventJob(
            sessionId: '',
            shopDomain: 'demo.myshopify.com',
            eventType: ConversionEvent::EVENT_CHAT_OPENED,
        ))->handle();

        $this->assertSame(0, ConversionEvent::query()->count());
    }
}
