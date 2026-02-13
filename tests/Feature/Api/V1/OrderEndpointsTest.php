<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;
use Tests\Mocks\MockShopifyClient;
use Tests\Helpers\ShopifyResponseFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Integration tests for Order API endpoints
 * 
 * Tests Requirements: 2.1, 2.2, 2.3, 2.5, 2.6
 */
class OrderEndpointsTest extends TestCase
{
    private MockShopifyClient $mockAdminClient;
    private MockShopifyClient $mockStorefrontClient;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->mockAdminClient = new MockShopifyClient();
        $this->mockStorefrontClient = new MockShopifyClient();
        
        $this->app->instance('App\Contracts\Shopify\AdminApiClientInterface', $this->mockAdminClient);
        $this->app->instance('App\Contracts\Shopify\StorefrontApiClientInterface', $this->mockStorefrontClient);
    }

    public function test_get_customer_orders_returns_standardized_response(): void
    {
        // Arrange
        $accessToken = 'test-access-token';
        $orders = [
            ShopifyResponseFactory::order(['orderNumber' => 1001]),
            ShopifyResponseFactory::order(['orderNumber' => 1002]),
        ];

        $customer = ShopifyResponseFactory::customer([
            'id' => 'gid://shopify/Customer/123',
            'email' => 'test@example.com',
        ]);

        // Mock customer lookup
        $this->mockStorefrontClient->mockResponse(
            'storefront/customer/get_customer.graphql',
            ShopifyResponseFactory::successResponse('customer', $customer)
        );

        // Mock orders fetch
        $this->mockAdminClient->mockResponse(
            'admin/orders/get_customer_orders.graphql',
            [
                'data' => [
                    'orders' => ShopifyResponseFactory::paginatedResponse($orders, false, null)
                ]
            ]
        );

        // Act
        $response = $this->getJson('/api/v1/orders', [
            'Authorization' => 'Bearer ' . $accessToken
        ]);

        // Assert - Requirement 2.1: Maintain identical API response structures
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'orders',
            ],
            'meta'
        ]);

        $response->assertJson([
            'success' => true,
        ]);

        $this->assertIsArray($response->json('data.orders'));
        $this->assertCount(2, $response->json('data.orders'));
    }

    public function test_get_order_by_id_returns_order_details(): void
    {
        // Arrange
        $accessToken = 'test-access-token';
        $orderId = 'gid://shopify/Order/123456';
        
        $order = ShopifyResponseFactory::order([
            'id' => $orderId,
            'orderNumber' => 1001,
            'financialStatus' => 'PAID',
        ]);

        $customer = ShopifyResponseFactory::customer([
            'id' => 'gid://shopify/Customer/123',
        ]);

        // Mock customer lookup
        $this->mockStorefrontClient->mockResponse(
            'storefront/customer/get_customer.graphql',
            ShopifyResponseFactory::successResponse('customer', $customer)
        );

        // Mock order fetch
        $this->mockAdminClient->mockResponse(
            'admin/orders/get_order.graphql',
            ShopifyResponseFactory::successResponse('order', $order)
        );

        // Act
        $response = $this->getJson('/api/v1/orders/' . urlencode($orderId), [
            'Authorization' => 'Bearer ' . $accessToken
        ]);

        // Assert - Requirement 2.2: Maintain identical API endpoint paths
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'order' => [
                    'id',
                    'order_number',
                    'financial_status',
                    'line_items',
                ]
            ],
            'meta'
        ]);

        $response->assertJson([
            'success' => true,
            'data' => [
                'order' => [
                    'id' => $orderId,
                    'order_number' => 1001,
                ]
            ]
        ]);
    }

    public function test_get_orders_requires_authentication(): void
    {
        // Act - No authentication header
        $response = $this->getJson('/api/v1/orders');

        // Assert - Requirement 2.3: Maintain identical request validation rules
        $response->assertStatus(401);
        $response->assertJson([
            'success' => false,
        ]);
    }

    public function test_get_order_not_found_returns_404(): void
    {
        // Arrange
        $accessToken = 'test-access-token';
        $orderId = 'gid://shopify/Order/nonexistent';

        $customer = ShopifyResponseFactory::customer([
            'id' => 'gid://shopify/Customer/123',
        ]);

        $this->mockStorefrontClient->mockResponse(
            'storefront/customer/get_customer.graphql',
            ShopifyResponseFactory::successResponse('customer', $customer)
        );

        $this->mockAdminClient->mockResponse(
            'admin/orders/get_order.graphql',
            ShopifyResponseFactory::successResponse('order', null)
        );

        // Act
        $response = $this->getJson('/api/v1/orders/' . urlencode($orderId), [
            'Authorization' => 'Bearer ' . $accessToken
        ]);

        // Assert - Requirement 2.5: Minimize changes to Mobile_App integration contracts
        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
        ]);
    }

    public function test_order_response_includes_correlation_id(): void
    {
        // Arrange
        $accessToken = 'test-access-token';
        $orders = [ShopifyResponseFactory::order()];

        $customer = ShopifyResponseFactory::customer();

        $this->mockStorefrontClient->mockResponse(
            'storefront/customer/get_customer.graphql',
            ShopifyResponseFactory::successResponse('customer', $customer)
        );

        $this->mockAdminClient->mockResponse(
            'admin/orders/get_customer_orders.graphql',
            [
                'data' => [
                    'orders' => ShopifyResponseFactory::paginatedResponse($orders, false, null)
                ]
            ]
        );

        // Act
        $response = $this->getJson('/api/v1/orders', [
            'Authorization' => 'Bearer ' . $accessToken
        ]);

        // Assert - Requirement 2.6: Maintain identical behavior
        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('meta.correlation_id'));
        $this->assertNotEmpty($response->json('meta.timestamp'));
        $this->assertEquals('v1', $response->json('meta.version'));
    }
}
