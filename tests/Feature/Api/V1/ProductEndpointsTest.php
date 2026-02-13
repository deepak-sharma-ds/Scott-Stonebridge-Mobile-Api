<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;
use Tests\Mocks\MockShopifyClient;
use Tests\Helpers\ShopifyResponseFactory;
use App\Services\Shopify\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Integration tests for Product API endpoints
 * 
 * Tests Requirements: 2.1, 2.2, 2.3, 2.5, 2.6
 */
class ProductEndpointsTest extends TestCase
{
    private MockShopifyClient $mockClient;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->mockClient = new MockShopifyClient();
        $this->app->instance('App\Contracts\Shopify\StorefrontApiClientInterface', $this->mockClient);
    }

    public function test_get_products_returns_standardized_response_format(): void
    {
        // Arrange
        $products = [
            ShopifyResponseFactory::product(['title' => 'Product 1', 'handle' => 'product-1']),
            ShopifyResponseFactory::product(['title' => 'Product 2', 'handle' => 'product-2']),
        ];

        $this->mockClient->mockResponse(
            'storefront/products/get_products.graphql',
            [
                'data' => [
                    'products' => ShopifyResponseFactory::paginatedResponse($products, true, 'cursor_123')
                ]
            ]
        );

        // Act
        $response = $this->getJson('/api/v1/products?limit=10');

        // Assert - Requirement 2.1: Maintain identical API response structures
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'products',
                'pagination' => ['next_cursor', 'has_more']
            ],
            'meta' => ['correlation_id', 'timestamp', 'version']
        ]);

        // Requirement 9.1: Success response format
        $response->assertJson([
            'success' => true,
        ]);

        $this->assertIsArray($response->json('data.products'));
        $this->assertCount(2, $response->json('data.products'));
    }

    public function test_get_product_by_handle_returns_product_detail(): void
    {
        // Arrange
        $product = ShopifyResponseFactory::product([
            'title' => 'Test Product',
            'handle' => 'test-product',
            'description' => 'A test product description',
        ]);

        $this->mockClient->mockResponse(
            'storefront/products/get_product.graphql',
            ShopifyResponseFactory::successResponse('product', $product)
        );

        // Act
        $response = $this->getJson('/api/v1/products/test-product');

        // Assert - Requirement 2.2: Maintain identical API endpoint paths
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'product' => [
                    'id',
                    'title',
                    'handle',
                    'description',
                    'variants',
                    'images',
                ]
            ],
            'meta'
        ]);

        $response->assertJson([
            'success' => true,
            'data' => [
                'product' => [
                    'title' => 'Test Product',
                    'handle' => 'test-product',
                ]
            ]
        ]);
    }

    public function test_search_products_returns_filtered_results(): void
    {
        // Arrange
        $products = [
            ShopifyResponseFactory::product(['title' => 'Blue Shirt', 'handle' => 'blue-shirt']),
            ShopifyResponseFactory::product(['title' => 'Blue Jeans', 'handle' => 'blue-jeans']),
        ];

        $this->mockClient->mockResponse(
            'storefront/products/search_products.graphql',
            [
                'data' => [
                    'products' => ShopifyResponseFactory::paginatedResponse($products, false, null)
                ]
            ]
        );

        // Act
        $response = $this->getJson('/api/v1/products/search?query=blue&limit=10');

        // Assert
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        $this->assertIsArray($response->json('data.products'));
        $this->assertCount(2, $response->json('data.products'));
    }

    public function test_get_products_validates_request_parameters(): void
    {
        // Act - Invalid limit parameter
        $response = $this->getJson('/api/v1/products?limit=invalid');

        // Assert - Requirement 2.3: Maintain identical request validation rules
        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
        ]);
    }

    public function test_get_product_returns_404_for_nonexistent_product(): void
    {
        // Arrange
        $this->mockClient->mockResponse(
            'storefront/products/get_product.graphql',
            ShopifyResponseFactory::successResponse('product', null)
        );

        // Act
        $response = $this->getJson('/api/v1/products/nonexistent-product');

        // Assert - Requirement 2.5: Minimize changes to Mobile_App integration contracts
        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
        ]);
    }

    public function test_response_includes_correlation_id(): void
    {
        // Arrange
        $products = [ShopifyResponseFactory::product()];
        $this->mockClient->mockResponse(
            'storefront/products/get_products.graphql',
            [
                'data' => [
                    'products' => ShopifyResponseFactory::paginatedResponse($products, false, null)
                ]
            ]
        );

        // Act
        $response = $this->getJson('/api/v1/products');

        // Assert - Requirement 2.6: Maintain identical behavior
        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('meta.correlation_id'));
        $this->assertNotEmpty($response->json('meta.timestamp'));
        $this->assertEquals('v1', $response->json('meta.version'));
    }

    public function test_response_does_not_expose_shopify_internal_fields(): void
    {
        // Arrange
        $product = ShopifyResponseFactory::product();
        $this->mockClient->mockResponse(
            'storefront/products/get_product.graphql',
            ShopifyResponseFactory::successResponse('product', $product)
        );

        // Act
        $response = $this->getJson('/api/v1/products/test-product');

        // Assert - Requirement 9.3: Do not expose raw GraphQL response structures
        $response->assertStatus(200);
        $productData = $response->json('data.product');
        
        $this->assertArrayNotHasKey('__typename', $productData);
        $this->assertArrayNotHasKey('edges', $productData);
        $this->assertArrayNotHasKey('nodes', $productData);
    }
}
