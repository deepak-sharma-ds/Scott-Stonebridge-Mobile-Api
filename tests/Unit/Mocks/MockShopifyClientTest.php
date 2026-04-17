<?php

namespace Tests\Unit\Mocks;

use Tests\Mocks\MockShopifyClient;
use Tests\TestCase;

class MockShopifyClientTest extends TestCase
{
    private MockShopifyClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new MockShopifyClient();
    }

    public function test_mock_response_can_be_configured(): void
    {
        $queryPath = 'storefront/products/get_product.graphql';
        $response = ['data' => ['product' => ['id' => '123', 'title' => 'Test Product']]];

        $this->client->mockResponse($queryPath, $response);

        $this->assertTrue($this->client->hasMockFor($queryPath));
    }

    public function test_query_returns_mocked_response(): void
    {
        $queryPath = 'storefront/products/get_product.graphql';
        $response = ['data' => ['product' => ['id' => '123', 'title' => 'Test Product']]];

        $this->client->mockResponse($queryPath, $response);

        $result = $this->client->query($queryPath, ['handle' => 'test-product']);

        $this->assertEquals($response, $result);
    }

    public function test_query_throws_exception_when_no_mock_configured(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No mock response configured for query path: unknown.graphql');

        $this->client->query('unknown.graphql');
    }

    public function test_with_retry_configures_retry_behavior(): void
    {
        $result = $this->client->withRetry(3, 100);

        $this->assertSame($this->client, $result);
        $this->assertEquals([
            'maxAttempts' => 3,
            'delayMs' => 100,
        ], $this->client->getRetryConfig());
    }

    public function test_with_circuit_breaker_configures_circuit_breaker(): void
    {
        $result = $this->client->withCircuitBreaker('shopify-api');

        $this->assertSame($this->client, $result);
        $this->assertEquals('shopify-api', $this->client->getCircuitBreakerName());
    }

    public function test_with_cache_configures_caching(): void
    {
        $result = $this->client->withCache(300, ['products', 'GBP']);

        $this->assertSame($this->client, $result);
        $this->assertEquals([
            'ttl' => 300,
            'tags' => ['products', 'GBP'],
        ], $this->client->getCacheConfig());
    }

    public function test_mock_response_with_metrics_sets_performance_data(): void
    {
        $queryPath = 'storefront/products/get_product.graphql';
        $response = ['data' => ['product' => ['id' => '123']]];

        $this->client->mockResponseWithMetrics($queryPath, $response, 150.5, 42);

        $this->assertEquals(150.5, $this->client->getLastRequestDuration());
        $this->assertEquals(42, $this->client->getLastRequestCost());
    }

    public function test_get_last_request_duration_returns_default_zero(): void
    {
        $this->assertEquals(0.0, $this->client->getLastRequestDuration());
    }

    public function test_get_last_request_cost_returns_null_by_default(): void
    {
        $this->assertNull($this->client->getLastRequestCost());
    }

    public function test_clear_mocks_resets_all_state(): void
    {
        $this->client->mockResponseWithMetrics('test.graphql', ['data' => []], 100.0, 10);
        $this->client->withRetry(3, 100);
        $this->client->withCircuitBreaker('test');
        $this->client->withCache(300, ['test']);

        $this->client->clearMocks();

        $this->assertFalse($this->client->hasMockFor('test.graphql'));
        $this->assertEquals(0.0, $this->client->getLastRequestDuration());
        $this->assertNull($this->client->getLastRequestCost());
        $this->assertNull($this->client->getRetryConfig());
        $this->assertNull($this->client->getCircuitBreakerName());
        $this->assertNull($this->client->getCacheConfig());
    }

    public function test_has_mock_for_returns_false_when_no_mock_exists(): void
    {
        $this->assertFalse($this->client->hasMockFor('nonexistent.graphql'));
    }

    public function test_method_chaining_works(): void
    {
        $queryPath = 'test.graphql';
        $response = ['data' => []];

        $this->client->mockResponse($queryPath, $response);

        $result = $this->client
            ->withRetry(3, 100)
            ->withCircuitBreaker('test')
            ->withCache(300, ['test'])
            ->query($queryPath);

        $this->assertEquals($response, $result);
        $this->assertNotNull($this->client->getRetryConfig());
        $this->assertNotNull($this->client->getCircuitBreakerName());
        $this->assertNotNull($this->client->getCacheConfig());
    }
}
