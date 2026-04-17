<?php

namespace Tests\Mocks;

use App\Contracts\Shopify\ShopifyClientInterface;
use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\Contracts\Shopify\AdminApiClientInterface;

/**
 * Mock implementation of ShopifyClientInterface for testing
 * 
 * This mock allows tests to simulate Shopify API responses without
 * making actual HTTP requests. Responses can be configured per query path.
 * 
 * Implements all Shopify client interfaces for maximum flexibility in tests.
 */
class MockShopifyClient implements ShopifyClientInterface, StorefrontApiClientInterface, AdminApiClientInterface
{
    /**
     * Mocked responses keyed by query path
     *
     * @var array<string, array>
     */
    private array $responses = [];

    /**
     * Last request duration in milliseconds
     *
     * @var float
     */
    private float $lastRequestDuration = 0.0;

    /**
     * Last request GraphQL cost
     *
     * @var int|null
     */
    private ?int $lastRequestCost = null;

    /**
     * Retry configuration
     *
     * @var array{maxAttempts: int, delayMs: int}|null
     */
    private ?array $retryConfig = null;

    /**
     * Circuit breaker name
     *
     * @var string|null
     */
    private ?string $circuitBreakerName = null;

    /**
     * Cache configuration
     *
     * @var array{ttl: int, tags: array}|null
     */
    private ?array $cacheConfig = null;

    /**
     * Configure a mock response for a specific query path
     *
     * @param string $queryPath Path to the GraphQL query file
     * @param array $response Response data to return
     * @return void
     */
    public function mockResponse(string $queryPath, array $response): void
    {
        $this->responses[$queryPath] = $response;
    }

    /**
     * Configure mock response with performance metrics
     *
     * @param string $queryPath Path to the GraphQL query file
     * @param array $response Response data to return
     * @param float $duration Request duration in milliseconds
     * @param int|null $cost GraphQL cost
     * @return void
     */
    public function mockResponseWithMetrics(
        string $queryPath,
        array $response,
        float $duration = 100.0,
        ?int $cost = null
    ): void {
        $this->responses[$queryPath] = $response;
        $this->lastRequestDuration = $duration;
        $this->lastRequestCost = $cost;
    }

    /**
     * Execute a GraphQL query
     *
     * @param string $queryPath Path to the GraphQL query file
     * @param array $variables Query variables
     * @return array Response data
     * @throws \Exception If no mock response is configured
     */
    public function query(string $queryPath, array $variables = []): array
    {
        if (!isset($this->responses[$queryPath])) {
            throw new \Exception("No mock response configured for query path: {$queryPath}");
        }

        return $this->responses[$queryPath];
    }

    /**
     * Configure retry behavior for the next request
     *
     * @param int $maxAttempts Maximum number of retry attempts
     * @param int $delayMs Initial delay in milliseconds
     * @return self
     */
    public function withRetry(int $maxAttempts, int $delayMs): self
    {
        $this->retryConfig = [
            'maxAttempts' => $maxAttempts,
            'delayMs' => $delayMs,
        ];

        return $this;
    }

    /**
     * Enable circuit breaker for the next request
     *
     * @param string $breakerName Circuit breaker identifier
     * @return self
     */
    public function withCircuitBreaker(string $breakerName): self
    {
        $this->circuitBreakerName = $breakerName;

        return $this;
    }

    /**
     * Enable caching for the next request
     *
     * @param int $ttl Cache time-to-live in seconds
     * @param array $tags Cache tags
     * @return self
     */
    public function withCache(int $ttl, array $tags = []): self
    {
        $this->cacheConfig = [
            'ttl' => $ttl,
            'tags' => $tags,
        ];

        return $this;
    }

    /**
     * Get the duration of the last request in milliseconds
     *
     * @return float
     */
    public function getLastRequestDuration(): float
    {
        return $this->lastRequestDuration;
    }

    /**
     * Get the GraphQL cost of the last request
     *
     * @return int|null
     */
    public function getLastRequestCost(): ?int
    {
        return $this->lastRequestCost;
    }

    /**
     * Get the retry configuration
     *
     * @return array{maxAttempts: int, delayMs: int}|null
     */
    public function getRetryConfig(): ?array
    {
        return $this->retryConfig;
    }

    /**
     * Get the circuit breaker name
     *
     * @return string|null
     */
    public function getCircuitBreakerName(): ?string
    {
        return $this->circuitBreakerName;
    }

    /**
     * Get the cache configuration
     *
     * @return array{ttl: int, tags: array}|null
     */
    public function getCacheConfig(): ?array
    {
        return $this->cacheConfig;
    }

    /**
     * Clear all mocked responses
     *
     * @return void
     */
    public function clearMocks(): void
    {
        $this->responses = [];
        $this->lastRequestDuration = 0.0;
        $this->lastRequestCost = null;
        $this->retryConfig = null;
        $this->circuitBreakerName = null;
        $this->cacheConfig = null;
    }

    /**
     * Check if a mock response exists for a query path
     *
     * @param string $queryPath Path to the GraphQL query file
     * @return bool
     */
    public function hasMockFor(string $queryPath): bool
    {
        return isset($this->responses[$queryPath]);
    }

    /**
     * Query with currency context (StorefrontApiClientInterface)
     *
     * @param string $queryPath Path to the GraphQL query file
     * @param array $variables Query variables
     * @param string|null $currencyCode Currency code (ISO 4217)
     * @return array Response data
     */
    public function queryWithCurrency(string $queryPath, array $variables = [], ?string $currencyCode = null): array
    {
        return $this->query($queryPath, $variables);
    }

    /**
     * Query with automatic caching (StorefrontApiClientInterface)
     *
     * @param string $queryPath Path to the GraphQL query file
     * @param array $variables Query variables
     * @param string $resourceType Resource type for cache tagging
     * @return array Response data
     */
    public function queryWithCache(string $queryPath, array $variables = [], string $resourceType = 'storefront'): array
    {
        return $this->query($queryPath, $variables);
    }

    /**
     * Query with both currency context and caching (StorefrontApiClientInterface)
     *
     * @param string $queryPath Path to the GraphQL query file
     * @param array $variables Query variables
     * @param string $resourceType Resource type for cache tagging
     * @param string|null $currencyCode Currency code (ISO 4217)
     * @return array Response data
     */
    public function queryWithCurrencyAndCache(
        string $queryPath,
        array $variables = [],
        string $resourceType = 'storefront',
        ?string $currencyCode = null
    ): array {
        return $this->query($queryPath, $variables);
    }
}
