<?php

namespace Tests\Unit\Services;

use App\Services\Cache\ShopifyCacheStrategy;
use Tests\TestCase;

class ShopifyCacheStrategyTest extends TestCase
{
    private ShopifyCacheStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new ShopifyCacheStrategy();
    }

    /** @test */
    public function it_generates_cache_key_with_operation_only()
    {
        $key = $this->strategy->getCacheKey('product.list', []);

        $this->assertStringStartsWith('shopify:product.list', $key);
    }

    /** @test */
    public function it_generates_cache_key_with_currency()
    {
        $key = $this->strategy->getCacheKey('product.list', ['currency' => 'GBP']);

        $this->assertStringContainsString('shopify:product.list:gbp', $key);
    }

    /** @test */
    public function it_generates_cache_key_with_params()
    {
        $key = $this->strategy->getCacheKey('product.get', [
            'handle' => 'test-product',
            'currency' => 'USD',
        ]);

        $this->assertStringStartsWith('shopify:product.get:usd:', $key);
        $this->assertStringContainsString('usd', $key);
    }

    /** @test */
    public function it_generates_consistent_cache_keys_regardless_of_param_order()
    {
        $key1 = $this->strategy->getCacheKey('product.list', [
            'limit' => 10,
            'cursor' => 'abc123',
            'currency' => 'GBP',
        ]);

        $key2 = $this->strategy->getCacheKey('product.list', [
            'currency' => 'GBP',
            'cursor' => 'abc123',
            'limit' => 10,
        ]);

        $this->assertEquals($key1, $key2);
    }

    /** @test */
    public function it_generates_different_cache_keys_for_different_currencies()
    {
        $keyGBP = $this->strategy->getCacheKey('product.list', ['currency' => 'GBP']);
        $keyUSD = $this->strategy->getCacheKey('product.list', ['currency' => 'USD']);

        $this->assertNotEquals($keyGBP, $keyUSD);
    }

    /** @test */
    public function it_generates_cache_tags_with_resource_type()
    {
        $tags = $this->strategy->getCacheTags('product.list', []);

        $this->assertContains('shopify:product', $tags);
        $this->assertContains('shopify:operation:product.list', $tags);
    }

    /** @test */
    public function it_generates_cache_tags_with_currency()
    {
        $tags = $this->strategy->getCacheTags('product.list', ['currency' => 'GBP']);

        $this->assertContains('shopify:product', $tags);
        $this->assertContains('shopify:currency:gbp', $tags);
        $this->assertContains('shopify:operation:product.list', $tags);
    }

    /** @test */
    public function it_generates_cache_tags_for_collection_operations()
    {
        $tags = $this->strategy->getCacheTags('collection.get', ['currency' => 'USD']);

        $this->assertContains('shopify:collection', $tags);
        $this->assertContains('shopify:currency:usd', $tags);
        $this->assertContains('shopify:operation:collection.get', $tags);
    }

    /** @test */
    public function it_returns_correct_ttl_for_product_operations()
    {
        config(['shopify.cache.ttl.product' => 900]);

        $ttl = $this->strategy->getCacheTTL('product.list');

        $this->assertEquals(900, $ttl);
    }

    /** @test */
    public function it_returns_correct_ttl_for_collection_operations()
    {
        config(['shopify.cache.ttl.collection' => 1800]);

        $ttl = $this->strategy->getCacheTTL('collection.get');

        $this->assertEquals(1800, $ttl);
    }

    /** @test */
    public function it_returns_correct_ttl_for_currency_operations()
    {
        config(['shopify.cache.ttl.currency' => 86400]);

        $ttl = $this->strategy->getCacheTTL('currency.list');

        $this->assertEquals(86400, $ttl);
    }

    /** @test */
    public function it_returns_correct_ttl_for_cart_operations()
    {
        config(['shopify.cache.ttl.cart' => 3600]);

        $ttl = $this->strategy->getCacheTTL('cart.get');

        $this->assertEquals(3600, $ttl);
    }

    /** @test */
    public function it_returns_zero_ttl_for_unknown_operations()
    {
        $ttl = $this->strategy->getCacheTTL('unknown.operation');

        $this->assertEquals(0, $ttl);
    }

    /** @test */
    public function it_should_cache_product_operations()
    {
        config(['shopify.cache.enabled' => true]);

        $this->assertTrue($this->strategy->shouldCache('product.list'));
        $this->assertTrue($this->strategy->shouldCache('product.get'));
        $this->assertTrue($this->strategy->shouldCache('product.search'));
        $this->assertTrue($this->strategy->shouldCache('product.featured'));
    }

    /** @test */
    public function it_should_cache_collection_operations()
    {
        config(['shopify.cache.enabled' => true]);

        $this->assertTrue($this->strategy->shouldCache('collection.list'));
        $this->assertTrue($this->strategy->shouldCache('collection.get'));
    }

    /** @test */
    public function it_should_cache_currency_operations()
    {
        config(['shopify.cache.enabled' => true]);

        $this->assertTrue($this->strategy->shouldCache('currency.list'));
    }

    /** @test */
    public function it_should_cache_cart_get_operations()
    {
        config(['shopify.cache.enabled' => true]);

        $this->assertTrue($this->strategy->shouldCache('cart.get'));
    }

    /** @test */
    public function it_should_not_cache_when_caching_is_disabled()
    {
        config(['shopify.cache.enabled' => false]);

        $this->assertFalse($this->strategy->shouldCache('product.list'));
        $this->assertFalse($this->strategy->shouldCache('collection.get'));
    }

    /** @test */
    public function it_should_not_cache_mutation_operations()
    {
        config(['shopify.cache.enabled' => true]);

        $this->assertFalse($this->strategy->shouldCache('cart.create'));
        $this->assertFalse($this->strategy->shouldCache('cart.addItem'));
        $this->assertFalse($this->strategy->shouldCache('cart.updateItem'));
        $this->assertFalse($this->strategy->shouldCache('order.create'));
    }

    /** @test */
    public function it_normalizes_currency_to_lowercase_in_cache_keys()
    {
        $keyUppercase = $this->strategy->getCacheKey('product.list', ['currency' => 'GBP']);
        $keyLowercase = $this->strategy->getCacheKey('product.list', ['currency' => 'gbp']);

        $this->assertEquals($keyUppercase, $keyLowercase);
    }

    /** @test */
    public function it_normalizes_currency_to_lowercase_in_cache_tags()
    {
        $tagsUppercase = $this->strategy->getCacheTags('product.list', ['currency' => 'GBP']);
        $tagsLowercase = $this->strategy->getCacheTags('product.list', ['currency' => 'gbp']);

        $this->assertEquals($tagsUppercase, $tagsLowercase);
    }
}

