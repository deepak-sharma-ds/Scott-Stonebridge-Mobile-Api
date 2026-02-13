# Testing Infrastructure

This directory contains the testing infrastructure for the Laravel Shopify Middleware application. The testing infrastructure provides tools for creating isolated, fast, and reliable tests without making real API calls to Shopify.

## Overview

The testing infrastructure consists of two main components:

1. **MockShopifyClient** - A mock implementation of `ShopifyClientInterface` for simulating Shopify API responses
2. **ShopifyResponseFactory** - A factory for generating realistic Shopify API response data

## MockShopifyClient

Located in `tests/Mocks/MockShopifyClient.php`

The `MockShopifyClient` implements the `ShopifyClientInterface` and allows you to configure mock responses for specific GraphQL query paths. This enables testing services and controllers without making actual HTTP requests to Shopify.

### Basic Usage

```php
use Tests\Mocks\MockShopifyClient;

$mockClient = new MockShopifyClient();

// Configure a mock response
$mockClient->mockResponse('storefront/products/get_product.graphql', [
    'data' => [
        'product' => [
            'id' => 'gid://shopify/Product/123',
            'title' => 'Test Product',
            'handle' => 'test-product',
        ],
    ],
]);

// Query the mock client
$response = $mockClient->query('storefront/products/get_product.graphql', [
    'handle' => 'test-product',
]);
```

### Advanced Features

#### Mock with Performance Metrics

```php
$mockClient->mockResponseWithMetrics(
    'storefront/products/get_product.graphql',
    ['data' => ['product' => $productData]],
    duration: 125.5,  // milliseconds
    cost: 42          // GraphQL cost
);

// Later, check the metrics
$duration = $mockClient->getLastRequestDuration();
$cost = $mockClient->getLastRequestCost();
```

#### Configure Client Behavior

```php
$mockClient
    ->withRetry(3, 100)                    // 3 retries, 100ms initial delay
    ->withCircuitBreaker('shopify-api')    // Enable circuit breaker
    ->withCache(300, ['products', 'GBP']); // Cache for 300s with tags

// Verify configuration
$retryConfig = $mockClient->getRetryConfig();
$breakerName = $mockClient->getCircuitBreakerName();
$cacheConfig = $mockClient->getCacheConfig();
```

#### Clear Mocks

```php
$mockClient->clearMocks(); // Reset all mocked responses and state
```

## ShopifyResponseFactory

Located in `tests/Helpers/ShopifyResponseFactory.php`

The `ShopifyResponseFactory` provides factory methods for generating realistic Shopify API response data. All factory methods support custom overrides for flexibility.

### Available Factory Methods

#### Product

```php
use Tests\Helpers\ShopifyResponseFactory;

// Generate a product with default values
$product = ShopifyResponseFactory::product();

// Generate a product with custom values
$product = ShopifyResponseFactory::product([
    'title' => 'Premium Headphones',
    'handle' => 'premium-headphones',
    'vendor' => 'AudioTech',
    'tags' => ['electronics', 'audio', 'premium'],
]);
```

#### Product Variant

```php
$variant = ShopifyResponseFactory::productVariant([
    'title' => 'Large / Blue',
    'price' => [
        'amount' => '49.99',
        'currencyCode' => 'GBP',
    ],
    'quantityAvailable' => 25,
]);
```

#### Cart

```php
// Generate a cart with default line items
$cart = ShopifyResponseFactory::cart();

// Generate a cart with custom line items
$cart = ShopifyResponseFactory::cart([
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
```

#### Order

```php
$order = ShopifyResponseFactory::order([
    'orderNumber' => 1234,
    'financialStatus' => 'PAID',
    'fulfillmentStatus' => 'FULFILLED',
]);
```

#### Customer

```php
$customer = ShopifyResponseFactory::customer([
    'firstName' => 'Jane',
    'lastName' => 'Smith',
    'email' => 'jane.smith@example.com',
    'addresses' => [
        ShopifyResponseFactory::address([
            'city' => 'Manchester',
            'country' => 'United Kingdom',
        ]),
    ],
]);
```

#### Collection

```php
$collection = ShopifyResponseFactory::collection([
    'title' => 'Summer Collection',
    'handle' => 'summer-collection',
]);
```

### Helper Methods

#### Paginated Response

Wrap items in Shopify's edge/node structure with pagination metadata:

```php
$products = [
    ShopifyResponseFactory::product(['title' => 'Product 1']),
    ShopifyResponseFactory::product(['title' => 'Product 2']),
    ShopifyResponseFactory::product(['title' => 'Product 3']),
];

$paginated = ShopifyResponseFactory::paginatedResponse(
    $products,
    hasNextPage: true,
    endCursor: 'cursor_abc123'
);
```

#### Error Response

Generate GraphQL error responses:

```php
$error = ShopifyResponseFactory::errorResponse(
    'Product not found',
    'NOT_FOUND'
);
```

#### Success Response

Wrap data in a GraphQL success response:

```php
$productData = ShopifyResponseFactory::product();
$response = ShopifyResponseFactory::successResponse('product', $productData);
// Returns: ['data' => ['product' => $productData]]
```

## Complete Example

Here's a complete example of testing a service that uses the Shopify client:

```php
use Tests\Helpers\ShopifyResponseFactory;
use Tests\Mocks\MockShopifyClient;
use Tests\TestCase;
use App\Services\Shopify\ProductService;

class ProductServiceTest extends TestCase
{
    private MockShopifyClient $mockClient;
    private ProductService $productService;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create mock client
        $this->mockClient = new MockShopifyClient();
        
        // Inject mock client into service
        $this->productService = new ProductService($this->mockClient);
    }

    public function test_get_product_by_handle_returns_product_dto(): void
    {
        // Arrange: Create realistic product data
        $productData = ShopifyResponseFactory::product([
            'title' => 'Test Product',
            'handle' => 'test-product',
        ]);

        // Mock the Shopify API response
        $this->mockClient->mockResponse(
            'storefront/products/get_product.graphql',
            ShopifyResponseFactory::successResponse('product', $productData)
        );

        // Act: Call the service method
        $productDTO = $this->productService->getProductByHandle('test-product');

        // Assert: Verify the result
        $this->assertInstanceOf(ProductDTO::class, $productDTO);
        $this->assertEquals('Test Product', $productDTO->title);
        $this->assertEquals('test-product', $productDTO->handle);
    }

    public function test_get_product_handles_not_found_error(): void
    {
        // Arrange: Mock an error response
        $this->mockClient->mockResponse(
            'storefront/products/get_product.graphql',
            ShopifyResponseFactory::errorResponse('Product not found', 'NOT_FOUND')
        );

        // Act & Assert: Expect exception
        $this->expectException(ShopifyNotFoundException::class);
        $this->productService->getProductByHandle('nonexistent');
    }
}
```

## Testing DTOs

You can use the factory to test DTO transformation:

```php
public function test_product_dto_transforms_shopify_response_correctly(): void
{
    // Arrange: Create factory data
    $productData = ShopifyResponseFactory::product([
        'title' => 'Test Product',
        'availableForSale' => true,
    ]);

    // Act: Transform to DTO
    $productDTO = ProductDTO::fromShopifyResponse($productData);

    // Assert: Verify transformation
    $this->assertInstanceOf(ProductDTO::class, $productDTO);
    $this->assertEquals('Test Product', $productDTO->title);
    $this->assertTrue($productDTO->availableForSale);
    $this->assertNotEmpty($productDTO->variants);
}
```

## Best Practices

1. **Use MockShopifyClient for Service Tests**: When testing services that interact with Shopify, inject the mock client to avoid real API calls.

2. **Use ShopifyResponseFactory for Realistic Data**: Always use the factory to generate test data instead of manually creating arrays. This ensures your test data matches the actual Shopify API structure.

3. **Override Only What You Need**: Both the mock client and factory support overrides. Only override the specific fields you need for your test case.

4. **Clear Mocks Between Tests**: If you're reusing a mock client instance, call `clearMocks()` to reset state between tests.

5. **Test Error Cases**: Use `ShopifyResponseFactory::errorResponse()` to test how your code handles Shopify API errors.

6. **Verify Performance Metrics**: Use `mockResponseWithMetrics()` when testing performance logging or monitoring features.

## Running Tests

Run all tests:
```bash
php artisan test
```

Run specific test files:
```bash
php artisan test tests/Unit/Services/ProductServiceTest.php
```

Run tests with coverage:
```bash
php artisan test --coverage
```

## Examples

See `tests/Examples/TestingInfrastructureExampleTest.php` for comprehensive examples of using the testing infrastructure.

## Requirements

- **Requirement 20.4**: Factory methods for test data creation
- **Requirement 20.5**: Mock implementations for Shopify clients
- **Requirement 20.6**: Mockable dependencies via dependency injection
- **Requirement 20.7**: Test helpers for common testing scenarios
