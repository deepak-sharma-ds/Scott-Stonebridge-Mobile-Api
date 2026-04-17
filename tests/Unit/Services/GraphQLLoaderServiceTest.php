<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\GraphQL\GraphQLLoaderService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Exception;

class GraphQLLoaderServiceTest extends TestCase
{
    protected GraphQLLoaderService $loader;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a fresh instance for each test
        $this->loader = new GraphQLLoaderService();
        
        // Clear cache before each test
        Cache::flush();
    }

    /** @test */
    public function it_loads_graphql_query_from_file()
    {
        $query = $this->loader->load('storefront/products/get_all_products');
        
        $this->assertNotEmpty($query);
        $this->assertStringContainsString('query getAllProducts', $query);
        $this->assertStringContainsString('$limit', $query);
    }

    /** @test */
    public function it_normalizes_path_with_graphql_extension()
    {
        // Should work with or without .graphql extension
        $query1 = $this->loader->load('storefront/products/get_all_products');
        $query2 = $this->loader->load('storefront/products/get_all_products.graphql');
        
        $this->assertEquals($query1, $query2);
    }

    /** @test */
    public function it_rejects_path_traversal_attempts()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('path traversal detected');
        
        $this->loader->load('storefront/../admin/orders/get_order_details');
    }

    /** @test */
    public function it_rejects_absolute_paths()
    {
        $this->expectException(Exception::class);
        
        // Windows-style absolute path
        try {
            $this->loader->load('C:/etc/passwd');
            $this->fail('Should have thrown exception for Windows absolute path');
        } catch (Exception $e) {
            $this->assertStringContainsString('absolute paths not allowed', $e->getMessage());
        }
        
        // Unix-style absolute path - will be caught by namespace check
        $this->expectExceptionMessage('namespace forbidden');
        $this->loader->load('/etc/passwd');
    }

    /** @test */
    public function it_rejects_null_bytes()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('null bytes not allowed');
        
        $this->loader->load("storefront/products/test\0.graphql");
    }

    /** @test */
    public function it_rejects_invalid_characters()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid characters');
        
        $this->loader->load('storefront/products/test<script>');
    }

    /** @test */
    public function it_rejects_forbidden_namespaces()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('namespace forbidden');
        
        $this->loader->load('forbidden/namespace/query');
    }

    /** @test */
    public function it_rejects_excessively_deep_paths()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('maximum depth exceeded');
        
        $this->loader->load('storefront/a/b/c/d/e/f/query');
    }

    /** @test */
    public function it_allows_admin_namespace()
    {
        $query = $this->loader->load('admin/orders/get_order_details');
        
        $this->assertNotEmpty($query);
        $this->assertStringContainsString('query getOrder', $query);
    }

    /** @test */
    public function it_allows_storefront_namespace()
    {
        $query = $this->loader->load('storefront/cart/create_cart');
        
        $this->assertNotEmpty($query);
        $this->assertStringContainsString('mutation createCart', $query);
    }

    /** @test */
    public function it_caches_loaded_queries()
    {
        // Disable cache initially
        $this->loader->disableCache();
        
        // First load
        $query1 = $this->loader->load('storefront/products/get_all_products');
        
        // Enable cache and load again
        $this->app['config']->set('app.env', 'production');
        $loader2 = new GraphQLLoaderService();
        
        $query2 = $loader2->load('storefront/products/get_all_products');
        
        // Should be the same
        $this->assertEquals($query1, $query2);
        
        // Check cache exists
        $this->assertTrue(Cache::has('graphql_query_storefront/products/get_all_products.graphql'));
    }

    /** @test */
    public function it_can_disable_cache()
    {
        $this->loader->disableCache();
        
        $query = $this->loader->load('storefront/products/get_all_products');
        
        $this->assertNotEmpty($query);
        // Cache should not be set when disabled
        $this->assertFalse(Cache::has('graphql_query_storefront/products/get_all_products.graphql'));
    }

    /** @test */
    public function it_can_refresh_cached_query()
    {
        // Load and cache
        $query1 = $this->loader->load('storefront/products/get_all_products');
        
        // Refresh (clears cache and reloads)
        $query2 = $this->loader->refresh('storefront/products/get_all_products');
        
        $this->assertEquals($query1, $query2);
    }

    /** @test */
    public function it_throws_exception_for_nonexistent_file()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('GraphQL file not found');
        
        $this->loader->load('storefront/products/nonexistent_query');
    }

    /** @test */
    public function it_validates_graphql_content()
    {
        // This test assumes the file exists and contains valid GraphQL
        $query = $this->loader->load('storefront/products/get_all_products');
        
        // Should start with query, mutation, subscription, fragment, or {
        $trimmed = ltrim($query);
        $startsWithValid = 
            str_starts_with($trimmed, 'query') ||
            str_starts_with($trimmed, 'mutation') ||
            str_starts_with($trimmed, 'subscription') ||
            str_starts_with($trimmed, 'fragment') ||
            str_starts_with($trimmed, '{');
        
        $this->assertTrue($startsWithValid);
    }

    /** @test */
    public function it_logs_performance_metrics()
    {
        // Enable performance logging
        $this->loader->enablePerformanceLogging();
        
        // Disable cache to ensure we hit the performance threshold
        $this->loader->disableCache();
        
        Log::shouldReceive('channel')
            ->with('performance')
            ->once()
            ->andReturnSelf();
        
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'GraphQL query loaded' &&
                       isset($context['operation']) &&
                       isset($context['duration_ms']) &&
                       isset($context['cache_hit']) &&
                       $context['cache_hit'] === false;
            });
        
        $this->loader->load('storefront/products/get_all_products');
    }

    /** @test */
    public function it_logs_security_violations()
    {
        Log::shouldReceive('channel')
            ->with('error')
            ->andReturnSelf();
        
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'GraphQL path security violation' &&
                       isset($context['attempted_path']) &&
                       isset($context['reason']);
            });
        
        try {
            $this->loader->load('storefront/../admin/orders/get_order_details');
        } catch (Exception $e) {
            // Expected exception
        }
    }

    /** @test */
    public function it_can_disable_performance_logging()
    {
        $this->loader->disablePerformanceLogging();
        
        Log::shouldReceive('channel')->never();
        Log::shouldReceive('info')->never();
        
        $this->loader->load('storefront/products/get_all_products');
    }

    /** @test */
    public function it_handles_queries_with_parameterized_variables()
    {
        $query = $this->loader->load('storefront/products/get_product_details');
        
        $this->assertStringContainsString('$handle', $query);
        $this->assertStringContainsString('String!', $query);
    }

    /** @test */
    public function it_handles_mutations()
    {
        $query = $this->loader->load('storefront/cart/add_line_item');
        
        $this->assertStringContainsString('mutation', $query);
        $this->assertStringContainsString('$cartId', $query);
        $this->assertStringContainsString('$lines', $query);
    }

    /** @test */
    public function it_handles_admin_api_queries()
    {
        $query = $this->loader->load('admin/customers/get_customer');
        
        $this->assertStringContainsString('query', $query);
        $this->assertStringContainsString('$id', $query);
    }
}
