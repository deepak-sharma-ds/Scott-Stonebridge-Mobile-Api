<?php

namespace Tests\Unit\Clients;

use App\Clients\Shopify\AdminApiClient;
use App\Clients\Shopify\StorefrontApiClient;
use App\Exceptions\ShopifyApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class BaseShopifyClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set up test configuration
        Config::set('shopify.store_domain', 'test-store.myshopify.com');
        Config::set('shopify.api_version', '2024-07');
        Config::set('shopify.access_token', 'test-admin-token');
        Config::set('shopify.storefront_access_token', 'test-storefront-token');
        Config::set('shopify.http.timeout', 30);
        Config::set('shopify.http.connect_timeout', 10);
        Config::set('shopify.retry.enabled', true);
        Config::set('shopify.retry.max_attempts', 3);
        Config::set('shopify.retry.initial_delay_ms', 100);
        Config::set('shopify.retry.max_delay_ms', 5000);
        Config::set('shopify.retry.multiplier', 2.0);
        Config::set('shopify.retry.jitter', true);
        Config::set('shopify.circuit_breaker.enabled', false); // Disable for basic tests
    }

    public function test_admin_client_has_correct_endpoint()
    {
        $client = new AdminApiClient();
        
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('getEndpoint');
        $method->setAccessible(true);
        
        $endpoint = $method->invoke($client);
        
        $this->assertEquals(
            'https://test-store.myshopify.com/admin/api/2024-07/graphql.json',
            $endpoint
        );
    }

    public function test_storefront_client_has_correct_endpoint()
    {
        $client = new StorefrontApiClient();
        
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('getEndpoint');
        $method->setAccessible(true);
        
        $endpoint = $method->invoke($client);
        
        $this->assertEquals(
            'https://test-store.myshopify.com/api/2024-07/graphql.json',
            $endpoint
        );
    }

    public function test_admin_client_has_correct_auth_headers()
    {
        $client = new AdminApiClient();
        
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('getAuthHeaders');
        $method->setAccessible(true);
        
        $headers = $method->invoke($client);
        
        $this->assertArrayHasKey('X-Shopify-Access-Token', $headers);
        $this->assertEquals('test-admin-token', $headers['X-Shopify-Access-Token']);
    }

    public function test_storefront_client_has_correct_auth_headers()
    {
        $client = new StorefrontApiClient();
        
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('getAuthHeaders');
        $method->setAccessible(true);
        
        $headers = $method->invoke($client);
        
        $this->assertArrayHasKey('X-Shopify-Storefront-Access-Token', $headers);
        $this->assertEquals('test-storefront-token', $headers['X-Shopify-Storefront-Access-Token']);
    }

    public function test_admin_client_returns_correct_api_type()
    {
        $client = new AdminApiClient();
        
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('getApiType');
        $method->setAccessible(true);
        
        $apiType = $method->invoke($client);
        
        $this->assertEquals('admin', $apiType);
    }

    public function test_storefront_client_returns_correct_api_type()
    {
        $client = new StorefrontApiClient();
        
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('getApiType');
        $method->setAccessible(true);
        
        $apiType = $method->invoke($client);
        
        $this->assertEquals('storefront', $apiType);
    }

    public function test_with_retry_configures_retry_behavior()
    {
        $client = new AdminApiClient();
        
        $result = $client->withRetry(5, 200);
        
        $this->assertInstanceOf(AdminApiClient::class, $result);
        
        $reflection = new \ReflectionClass($client);
        $property = $reflection->getProperty('retryMaxAttempts');
        $property->setAccessible(true);
        
        $this->assertEquals(5, $property->getValue($client));
    }

    public function test_with_circuit_breaker_configures_breaker()
    {
        $client = new AdminApiClient();
        
        $result = $client->withCircuitBreaker('test-breaker');
        
        $this->assertInstanceOf(AdminApiClient::class, $result);
        
        $reflection = new \ReflectionClass($client);
        $property = $reflection->getProperty('circuitBreakerName');
        $property->setAccessible(true);
        
        $this->assertEquals('test-breaker', $property->getValue($client));
    }

    public function test_with_cache_configures_caching()
    {
        $client = new AdminApiClient();
        
        $result = $client->withCache(900, ['products', 'test']);
        
        $this->assertInstanceOf(AdminApiClient::class, $result);
        
        $reflection = new \ReflectionClass($client);
        
        $ttlProperty = $reflection->getProperty('cacheTtl');
        $ttlProperty->setAccessible(true);
        $this->assertEquals(900, $ttlProperty->getValue($client));
        
        $tagsProperty = $reflection->getProperty('cacheTags');
        $tagsProperty->setAccessible(true);
        $this->assertEquals(['products', 'test'], $tagsProperty->getValue($client));
    }

    public function test_calculate_next_delay_with_exponential_backoff()
    {
        $client = new AdminApiClient();
        
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('calculateNextDelay');
        $method->setAccessible(true);
        
        Config::set('shopify.retry.multiplier', 2.0);
        Config::set('shopify.retry.max_delay_ms', 5000);
        Config::set('shopify.retry.jitter', false); // Disable jitter for predictable test
        
        $nextDelay = $method->invoke($client, 100);
        
        $this->assertEquals(200, $nextDelay);
    }

    public function test_get_cache_key_generates_consistent_key()
    {
        $client = new AdminApiClient();
        
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('getCacheKey');
        $method->setAccessible(true);
        
        $key1 = $method->invoke($client, 'products/get_product', ['handle' => 'test']);
        $key2 = $method->invoke($client, 'products/get_product', ['handle' => 'test']);
        
        $this->assertEquals($key1, $key2);
    }

    public function test_get_cache_key_differs_for_different_variables()
    {
        $client = new AdminApiClient();
        
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('getCacheKey');
        $method->setAccessible(true);
        
        $key1 = $method->invoke($client, 'products/get_product', ['handle' => 'test1']);
        $key2 = $method->invoke($client, 'products/get_product', ['handle' => 'test2']);
        
        $this->assertNotEquals($key1, $key2);
    }

    public function test_storefront_cache_key_includes_currency()
    {
        $client = new StorefrontApiClient();
        
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('getCacheKey');
        $method->setAccessible(true);
        
        $keyGBP = $method->invoke($client, 'products/get_product', ['handle' => 'test', 'currency' => 'GBP']);
        $keyUSD = $method->invoke($client, 'products/get_product', ['handle' => 'test', 'currency' => 'USD']);
        
        $this->assertNotEquals($keyGBP, $keyUSD);
        $this->assertStringContainsString('GBP', $keyGBP);
        $this->assertStringContainsString('USD', $keyUSD);
    }
}
