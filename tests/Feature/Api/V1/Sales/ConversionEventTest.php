<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Sales;

use App\Jobs\Sales\StoreConversionEventJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase E — POST /api/v1/ai/analytics/event coverage.
 *
 * Endpoint must:
 *   - always return 200 on valid payload + dispatch job
 *   - reject unknown event_type at validation time
 *   - reject missing required fields
 */
class ConversionEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_store_returns_200_and_dispatches_job(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/v1/ai/analytics/event', [
            'session_id' => 'sess-conv-1',
            'shop_domain' => 'demo.myshopify.com',
            'event_type' => 'product_clicked',
            'product_id' => 'gid://shopify/Product/1',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.accepted', true);

        Queue::assertPushed(
            StoreConversionEventJob::class,
            fn (StoreConversionEventJob $job): bool => $job->sessionId === 'sess-conv-1'
                && $job->shopDomain === 'demo.myshopify.com'
                && $job->eventType === 'product_clicked'
                && $job->productId === 'gid://shopify/Product/1'
        );
    }

    public function test_store_accepts_order_placed_with_revenue(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/v1/ai/analytics/event', [
            'session_id' => 'sess-conv-2',
            'shop_domain' => 'demo.myshopify.com',
            'event_type' => 'order_placed',
            'order_id' => 'gid://shopify/Order/100',
            'revenue' => 49.99,
        ]);

        $response->assertOk();

        Queue::assertPushed(
            StoreConversionEventJob::class,
            fn (StoreConversionEventJob $job): bool => $job->eventType === 'order_placed'
                && $job->orderId === 'gid://shopify/Order/100'
                && $job->revenue === 49.99
        );
    }

    public function test_store_rejects_unknown_event_type(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/v1/ai/analytics/event', [
            'session_id' => 'sess-conv-3',
            'shop_domain' => 'demo.myshopify.com',
            'event_type' => 'unknown_funky_event',
        ]);

        $response->assertStatus(422);
        Queue::assertNotPushed(StoreConversionEventJob::class);
    }

    public function test_store_rejects_missing_fields(): void
    {
        $response = $this->postJson('/api/v1/ai/analytics/event', [
            'event_type' => 'chat_opened',
        ]);

        $response->assertStatus(422);
    }
}
