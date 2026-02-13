<?php

namespace Tests\Unit\Providers;

use Tests\TestCase;
use Illuminate\Support\Facades\Config;

class ShopifyConfigValidationTest extends TestCase
{
    /**
     * Test that required Shopify configuration keys exist.
     */
    public function test_required_shopify_configuration_exists(): void
    {
        $requiredKeys = [
            'shopify.store_domain',
            'shopify.api_version',
            'shopify.storefront_access_token',
        ];

        foreach ($requiredKeys as $key) {
            $value = config($key);
            $this->assertNotEmpty($value, "Configuration key '{$key}' should not be empty");
        }
    }

    /**
     * Test that cache TTL configuration has sensible defaults.
     */
    public function test_cache_ttl_configuration_has_defaults(): void
    {
        $this->assertIsInt(config('shopify.cache.ttl.product'));
        $this->assertIsInt(config('shopify.cache.ttl.collection'));
        $this->assertIsInt(config('shopify.cache.ttl.currency'));
        $this->assertIsInt(config('shopify.cache.ttl.cart'));

        $this->assertGreaterThan(0, config('shopify.cache.ttl.product'));
        $this->assertGreaterThan(0, config('shopify.cache.ttl.collection'));
        $this->assertGreaterThan(0, config('shopify.cache.ttl.currency'));
        $this->assertGreaterThan(0, config('shopify.cache.ttl.cart'));
    }

    /**
     * Test that HTTP configuration has sensible defaults.
     */
    public function test_http_configuration_has_defaults(): void
    {
        $this->assertIsInt(config('shopify.http.timeout'));
        $this->assertIsInt(config('shopify.http.connect_timeout'));

        $this->assertGreaterThan(0, config('shopify.http.timeout'));
        $this->assertGreaterThan(0, config('shopify.http.connect_timeout'));
    }

    /**
     * Test that retry configuration has sensible defaults.
     */
    public function test_retry_configuration_has_defaults(): void
    {
        $this->assertIsBool(config('shopify.retry.enabled'));
        $this->assertIsInt(config('shopify.retry.max_attempts'));
        $this->assertIsInt(config('shopify.retry.initial_delay_ms'));
        $this->assertIsInt(config('shopify.retry.max_delay_ms'));
        $this->assertIsFloat(config('shopify.retry.multiplier'));
        $this->assertIsBool(config('shopify.retry.jitter'));

        $this->assertGreaterThan(0, config('shopify.retry.max_attempts'));
        $this->assertGreaterThan(0, config('shopify.retry.initial_delay_ms'));
        $this->assertGreaterThan(0, config('shopify.retry.max_delay_ms'));
        $this->assertGreaterThan(1.0, config('shopify.retry.multiplier'));
    }

    /**
     * Test that circuit breaker configuration has sensible defaults.
     */
    public function test_circuit_breaker_configuration_has_defaults(): void
    {
        $this->assertIsBool(config('shopify.circuit_breaker.enabled'));
        $this->assertIsInt(config('shopify.circuit_breaker.failure_threshold'));
        $this->assertIsInt(config('shopify.circuit_breaker.success_threshold'));
        $this->assertIsInt(config('shopify.circuit_breaker.timeout_seconds'));
        $this->assertIsInt(config('shopify.circuit_breaker.window_seconds'));

        $this->assertGreaterThan(0, config('shopify.circuit_breaker.failure_threshold'));
        $this->assertGreaterThan(0, config('shopify.circuit_breaker.success_threshold'));
        $this->assertGreaterThan(0, config('shopify.circuit_breaker.timeout_seconds'));
        $this->assertGreaterThan(0, config('shopify.circuit_breaker.window_seconds'));
    }

    /**
     * Test that rate limit configuration has sensible defaults.
     */
    public function test_rate_limit_configuration_has_defaults(): void
    {
        $this->assertIsBool(config('shopify.rate_limit.enabled'));
        $this->assertIsInt(config('shopify.rate_limit.max_attempts'));
        $this->assertIsInt(config('shopify.rate_limit.decay_minutes'));

        $this->assertGreaterThan(0, config('shopify.rate_limit.max_attempts'));
        $this->assertGreaterThan(0, config('shopify.rate_limit.decay_minutes'));
    }

    /**
     * Test that GraphQL configuration has sensible defaults.
     */
    public function test_graphql_configuration_has_defaults(): void
    {
        $this->assertIsBool(config('shopify.graphql.verify_hashes'));
        $this->assertTrue(is_numeric(config('shopify.graphql.cache_minutes')));
        $this->assertIsBool(config('shopify.graphql.performance_logging'));
        $this->assertTrue(is_numeric(config('shopify.graphql.performance_threshold_ms')));

        $this->assertGreaterThan(0, (int)config('shopify.graphql.cache_minutes'));
        $this->assertGreaterThan(0, (int)config('shopify.graphql.performance_threshold_ms'));
    }

    /**
     * Test that all configuration sections are present.
     */
    public function test_all_configuration_sections_exist(): void
    {
        $sections = [
            'shopify.cache',
            'shopify.http',
            'shopify.retry',
            'shopify.circuit_breaker',
            'shopify.rate_limit',
            'shopify.graphql',
        ];

        foreach ($sections as $section) {
            $this->assertIsArray(config($section), "Configuration section '{$section}' should be an array");
        }
    }
}
