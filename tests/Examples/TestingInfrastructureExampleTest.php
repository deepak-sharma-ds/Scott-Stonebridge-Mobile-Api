<?php

namespace Tests\Examples;

use Tests\Helpers\ShopifyResponseFactory;
use Tests\Mocks\MockShopifyClient;
use Tests\TestCase;
use App\DTOs\Product\ProductDTO;
use App\DTOs\Cart\CartDTO;
use App\DTOs\Customer\CustomerDTO;

/**
 * Example test demonstrating how to use MockShopifyClient and ShopifyResponseFactory
 * 
 * This test class shows practical examples of using the testing infrastructure
 * to create isolated, fast, and reliable tests without making real API calls.
 */
class TestingInfrastructureExampleTest extends TestCase
{
    private MockShopifyClient $mockClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockClient = new MockShopifyClient();
    }

    public function test_example_mocking_product_query(): void
    {
        // Arrange: Create a realistic product response
        $productData = ShopifyResponseFactory::product([
            'title' => 'Premium Headphones',
            'handle' => 'premium-headphones',
            'vendor' => 'AudioTech',
        ]);

        // Mock the Shopify client response
        $queryPath = 'storefront/products/get_product.graphql';
        $this->mockClient->mockResponse($queryPath, [
            'data' => ['product' => $productData],
        ]);

        // Act: Query the mock client
        $response = $this->mockClient->query($queryPath, ['handle' => 'premium-headphones']);

        // Assert: Verify the response structure
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('product', $response['data']);
        $this->assertEquals('Premium Headphones', $response['data']['product']['title']);
        $this->assertEquals('AudioTech', $response['data']['product']['vendor']);
    }

    public function test_example_creating_dto_from_factory_data(): void
    {
        // Arrange: Create product data using factory
        $productData = ShopifyResponseFactory::product([
            'title' => 'Test Product',
            'handle' => 'test-product',
        ]);

        // Act: Create DTO from factory data
        $productDTO = ProductDTO::fromShopifyResponse($productData);

        // Assert: Verify DTO was created correctly
        $this->assertInstanceOf(ProductDTO::class, $productDTO);
        $this->assertEquals('Test Product', $productDTO->title);
        $this->assertEquals('test-product', $productDTO->handle);
        $this->assertNotEmpty($productDTO->variants);
    }

    public function test_example_mocking_cart_operations(): void
    {
        // Arrange: Create a cart with multiple items
        $cartData = ShopifyResponseFactory::cart([
            'lineItems' => [
                ShopifyResponseFactory::cartLineItem([
                    'quantity' => 2,
                    'merchandise' => [
                        'title' => 'Product A',
                        'price' => ['amount' => '25.00', 'currencyCode' => 'GBP'],
                    ],
                ]),
                ShopifyResponseFactory::cartLineItem([
                    'quantity' => 1,
                    'merchandise' => [
                        'title' => 'Product B',
                        'price' => ['amount' => '50.00', 'currencyCode' => 'GBP'],
                    ],
                ]),
            ],
        ]);

        // Mock the cart query
        $this->mockClient->mockResponse('storefront/cart/get_cart.graphql', [
            'data' => ['cart' => $cartData],
        ]);

        // Act: Query the cart
        $response = $this->mockClient->query('storefront/cart/get_cart.graphql', [
            'cartId' => 'test-cart-id',
        ]);

        // Create DTO from response
        $cartDTO = CartDTO::fromShopifyResponse($response['data']['cart']);

        // Assert: Verify cart structure and calculations
        $this->assertInstanceOf(CartDTO::class, $cartDTO);
        $this->assertCount(2, $cartDTO->lineItems);
        $this->assertEquals(3, $cartDTO->getTotalItems()); // 2 + 1
        $this->assertEquals('100.00', $cartDTO->cost['subtotal']); // 50 + 50
    }

    public function test_example_mocking_with_performance_metrics(): void
    {
        // Arrange: Create product data
        $productData = ShopifyResponseFactory::product();

        // Mock with performance metrics
        $queryPath = 'storefront/products/get_product.graphql';
        $this->mockClient->mockResponseWithMetrics(
            $queryPath,
            ['data' => ['product' => $productData]],
            duration: 125.5,
            cost: 42
        );

        // Act: Query and check metrics
        $response = $this->mockClient->query($queryPath);

        // Assert: Verify metrics are tracked
        $this->assertEquals(125.5, $this->mockClient->getLastRequestDuration());
        $this->assertEquals(42, $this->mockClient->getLastRequestCost());
    }

    public function test_example_mocking_error_responses(): void
    {
        // Arrange: Create an error response
        $errorResponse = ShopifyResponseFactory::errorResponse(
            'Product not found',
            'NOT_FOUND'
        );

        // Mock the error
        $queryPath = 'storefront/products/get_product.graphql';
        $this->mockClient->mockResponse($queryPath, $errorResponse);

        // Act: Query the mock client
        $response = $this->mockClient->query($queryPath, ['handle' => 'nonexistent']);

        // Assert: Verify error structure
        $this->assertArrayHasKey('errors', $response);
        $this->assertEquals('Product not found', $response['errors'][0]['message']);
        $this->assertEquals('NOT_FOUND', $response['errors'][0]['extensions']['code']);
    }

    public function test_example_mocking_paginated_responses(): void
    {
        // Arrange: Create multiple products
        $products = [
            ShopifyResponseFactory::product(['title' => 'Product 1']),
            ShopifyResponseFactory::product(['title' => 'Product 2']),
            ShopifyResponseFactory::product(['title' => 'Product 3']),
        ];

        // Wrap in paginated structure
        $paginatedData = ShopifyResponseFactory::paginatedResponse($products, hasNextPage: true);

        // Mock the query
        $this->mockClient->mockResponse('storefront/products/get_products.graphql', [
            'data' => ['products' => $paginatedData],
        ]);

        // Act: Query the products
        $response = $this->mockClient->query('storefront/products/get_products.graphql', [
            'first' => 3,
        ]);

        // Assert: Verify pagination structure
        $this->assertArrayHasKey('edges', $response['data']['products']);
        $this->assertArrayHasKey('pageInfo', $response['data']['products']);
        $this->assertCount(3, $response['data']['products']['edges']);
        $this->assertTrue($response['data']['products']['pageInfo']['hasNextPage']);
    }

    public function test_example_testing_client_configuration(): void
    {
        // Arrange: Configure the mock client
        $this->mockClient
            ->withRetry(3, 100)
            ->withCircuitBreaker('shopify-api')
            ->withCache(300, ['products', 'GBP']);

        // Assert: Verify configuration was set
        $this->assertEquals([
            'maxAttempts' => 3,
            'delayMs' => 100,
        ], $this->mockClient->getRetryConfig());

        $this->assertEquals('shopify-api', $this->mockClient->getCircuitBreakerName());

        $this->assertEquals([
            'ttl' => 300,
            'tags' => ['products', 'GBP'],
        ], $this->mockClient->getCacheConfig());
    }

    public function test_example_creating_customer_with_addresses(): void
    {
        // Arrange: Create customer with custom addresses
        $customerData = ShopifyResponseFactory::customer([
            'firstName' => 'Jane',
            'lastName' => 'Smith',
            'email' => 'jane.smith@example.com',
            'addresses' => [
                ShopifyResponseFactory::address([
                    'city' => 'Manchester',
                    'country' => 'United Kingdom',
                ]),
                ShopifyResponseFactory::address([
                    'city' => 'Birmingham',
                    'country' => 'United Kingdom',
                ]),
            ],
        ]);

        // Act: Create DTO
        $customerDTO = CustomerDTO::fromShopifyResponse($customerData);

        // Assert: Verify customer data
        $this->assertEquals('Jane', $customerDTO->firstName);
        $this->assertEquals('Smith', $customerDTO->lastName);
        $this->assertEquals('jane.smith@example.com', $customerDTO->email);
        $this->assertCount(2, $customerDTO->addresses);
        $this->assertTrue($customerDTO->hasAddresses());
    }

    public function test_example_clearing_mocks_between_tests(): void
    {
        // Arrange: Set up a mock
        $this->mockClient->mockResponse('test.graphql', ['data' => []]);
        $this->assertTrue($this->mockClient->hasMockFor('test.graphql'));

        // Act: Clear all mocks
        $this->mockClient->clearMocks();

        // Assert: Verify mocks are cleared
        $this->assertFalse($this->mockClient->hasMockFor('test.graphql'));
    }
}
