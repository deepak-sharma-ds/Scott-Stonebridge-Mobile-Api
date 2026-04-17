<?php

namespace App\Contracts\Shopify;

interface ShopifyClientInterface
{
    /**
     * Execute a GraphQL query
     *
     * @param string $queryPath Path to the GraphQL query file
     * @param array $variables Query variables
     * @return array Response data
     */
    public function query(string $queryPath, array $variables = []): array;

    /**
     * Configure retry behavior for the next request
     *
     * @param int $maxAttempts Maximum number of retry attempts
     * @param int $delayMs Initial delay in milliseconds
     * @return self
     */
    public function withRetry(int $maxAttempts, int $delayMs): self;

    /**
     * Enable circuit breaker for the next request
     *
     * @param string $breakerName Circuit breaker identifier
     * @return self
     */
    public function withCircuitBreaker(string $breakerName): self;

    /**
     * Enable caching for the next request
     *
     * @param int $ttl Cache time-to-live in seconds
     * @param array $tags Cache tags
     * @return self
     */
    public function withCache(int $ttl, array $tags = []): self;

    /**
     * Get the duration of the last request in milliseconds
     *
     * @return float
     */
    public function getLastRequestDuration(): float;

    /**
     * Get the GraphQL cost of the last request
     *
     * @return int|null
     */
    public function getLastRequestCost(): ?int;
}
