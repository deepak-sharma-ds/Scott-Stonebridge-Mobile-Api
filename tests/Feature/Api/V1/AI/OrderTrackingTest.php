<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\AI;

use App\Contracts\Shopify\AdminApiClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Tests\Mocks\MockShopifyClient;
use Tests\TestCase;

/**
 * GET /api/v1/ai/orders/track coverage.
 *
 * Admin API is fully mocked. The endpoint must:
 *   - return order data on (order_number, email) match
 *   - return 404 with order_not_found on no-match
 *   - return 404 with order_not_found on email mismatch (defense-in-depth)
 *   - reject missing required fields with 422
 *   - enforce 10/min/session rate limit
 */
class OrderTrackingTest extends TestCase
{
    use RefreshDatabase;

    private MockShopifyClient $shopify;

    /** Fixed UUID so rate-limit + cache-key tests share a stable bucket. */
    private const SESSION_ID = '11111111-2222-3333-4444-555555555555';

    private const GRAPHQL_PATH = 'admin/orders/get_order_by_name';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Cache::flush();

        $this->shopify = new MockShopifyClient;
        $this->app->instance(AdminApiClientInterface::class, $this->shopify);
    }

    public function test_returns_order_data_on_valid_match(): void
    {
        $this->shopify->mockResponse(self::GRAPHQL_PATH, [
            'data' => ['orders' => ['edges' => [['node' => [
                'id' => 'gid://shopify/Order/9000',
                'name' => '#1234',
                'email' => 'jane@example.com',
                'createdAt' => '2026-05-18T10:00:00Z',
                'displayFulfillmentStatus' => 'IN_TRANSIT',
                'displayFinancialStatus' => 'PAID',
                'shippingAddress' => ['city' => 'London', 'country' => 'United Kingdom'],
                'fulfillments' => [[
                    'status' => 'SUCCESS',
                    'estimatedDeliveryAt' => '2026-05-22',
                    'trackingInfo' => [[
                        'number' => '1Z999AA10123456784',
                        'url' => 'https://www.ups.com/track?tracknum=1Z999AA10123456784',
                        'company' => 'UPS',
                    ]],
                ]],
            ]]]]],
        ]);

        $response = $this->getJson(sprintf(
            '/api/v1/ai/orders/track?shop_domain=%s&session_id=%s&order_number=%s&email=%s',
            'demo.myshopify.com',
            self::SESSION_ID,
            '1234',
            'jane@example.com',
        ));

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.order_number', '1234');
        $response->assertJsonPath('data.status', 'in_transit');
        $response->assertJsonPath('data.tracking_number', '1Z999AA10123456784');
        $response->assertJsonPath('data.tracking_url', 'https://www.ups.com/track?tracknum=1Z999AA10123456784');
        $response->assertJsonPath('data.carrier', 'UPS');
        $response->assertJsonPath('data.estimated_delivery', '2026-05-22');
        $response->assertJsonPath('data.ship_to_city', 'London');
    }

    public function test_returns_404_when_no_match(): void
    {
        $this->shopify->mockResponse(self::GRAPHQL_PATH, [
            'data' => ['orders' => ['edges' => []]],
        ]);

        $response = $this->getJson(sprintf(
            '/api/v1/ai/orders/track?shop_domain=%s&session_id=%s&order_number=%s&email=%s',
            'demo.myshopify.com',
            self::SESSION_ID,
            '9999',
            'nobody@example.com',
        ));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('meta.error_code', 'order_not_found');
    }

    public function test_returns_404_when_email_mismatch_against_returned_order(): void
    {
        // Shopify search returned an order BUT the email on the order doesn't
        // match what the customer claimed. Defense-in-depth catches this.
        $this->shopify->mockResponse(self::GRAPHQL_PATH, [
            'data' => ['orders' => ['edges' => [['node' => [
                'id' => 'gid://shopify/Order/9001',
                'name' => '#5555',
                'email' => 'someone-else@example.com',
                'displayFulfillmentStatus' => 'FULFILLED',
                'displayFinancialStatus' => 'PAID',
                'shippingAddress' => null,
                'fulfillments' => [],
            ]]]]],
        ]);

        $response = $this->getJson(sprintf(
            '/api/v1/ai/orders/track?shop_domain=%s&session_id=%s&order_number=%s&email=%s',
            'demo.myshopify.com',
            self::SESSION_ID,
            '5555',
            'wrong@example.com',
        ));

        $response->assertStatus(404);
        $response->assertJsonPath('meta.error_code', 'order_not_found');
    }

    public function test_validates_required_fields(): void
    {
        $response = $this->getJson('/api/v1/ai/orders/track?shop_domain=demo.myshopify.com');

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('meta.error_code', 'VALIDATION_ERROR');
        // Project's error envelope nests validator output under meta.errors.
        $errors = $response->json('meta.errors') ?? [];
        $this->assertArrayHasKey('session_id', $errors);
        $this->assertArrayHasKey('order_number', $errors);
        $this->assertArrayHasKey('email', $errors);
    }

    public function test_validates_email_format(): void
    {
        $response = $this->getJson(sprintf(
            '/api/v1/ai/orders/track?shop_domain=%s&session_id=%s&order_number=%s&email=%s',
            'demo.myshopify.com',
            self::SESSION_ID,
            '1234',
            'not-an-email',
        ));

        $response->assertStatus(422);
        $errors = $response->json('meta.errors') ?? [];
        $this->assertArrayHasKey('email', $errors);
    }

    public function test_rate_limited_after_10_per_min(): void
    {
        // Re-enable throttle middleware just for this test.
        $this->app->forgetInstance(ThrottleRequests::class);
        $this->withMiddleware();

        $this->shopify->mockResponse(self::GRAPHQL_PATH, [
            'data' => ['orders' => ['edges' => []]],
        ]);

        $url = sprintf(
            '/api/v1/ai/orders/track?shop_domain=%s&session_id=%s&order_number=%s&email=%s',
            'demo.myshopify.com',
            self::SESSION_ID,
            '1234',
            'jane@example.com',
        );

        // First 10 calls — all 404 (mock returns empty edges) but still pass the limiter.
        for ($i = 0; $i < 10; $i++) {
            $this->getJson($url)->assertStatus(404);
        }

        // 11th call must hit the rate limiter.
        $this->getJson($url)->assertStatus(429);
    }

    public function test_maps_delivered_fulfilment_status_to_delivered(): void
    {
        $this->shopify->mockResponse(self::GRAPHQL_PATH, [
            'data' => ['orders' => ['edges' => [['node' => [
                'id' => 'gid://shopify/Order/9002',
                'name' => '#7777',
                'email' => 'jane@example.com',
                'displayFulfillmentStatus' => 'FULFILLED',
                'displayFinancialStatus' => 'PAID',
                'shippingAddress' => ['city' => 'Paris', 'country' => 'France'],
                'fulfillments' => [],
            ]]]]],
        ]);

        $response = $this->getJson(sprintf(
            '/api/v1/ai/orders/track?shop_domain=%s&session_id=%s&order_number=%s&email=%s',
            'demo.myshopify.com',
            self::SESSION_ID,
            '7777',
            'jane@example.com',
        ));

        $response->assertOk();
        $response->assertJsonPath('data.status', 'delivered');
        $response->assertJsonPath('data.tracking_number', null);
        $response->assertJsonPath('data.ship_to_city', 'Paris');
    }
}
