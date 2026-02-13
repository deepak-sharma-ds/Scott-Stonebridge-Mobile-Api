<?php

namespace Tests\Unit\Exceptions;

use App\Exceptions\ShopifyException;
use Tests\TestCase;

class ShopifyExceptionTest extends TestCase
{
    public function test_it_can_be_instantiated_with_message(): void
    {
        $exception = new ShopifyException('Test error message');

        $this->assertEquals('Test error message', $exception->getMessage());
        $this->assertEquals(500, $exception->getHttpStatusCode());
        $this->assertEquals('SHOPIFY_ERROR', $exception->getErrorCode());
    }

    public function test_it_can_be_instantiated_with_context(): void
    {
        $context = ['operation' => 'getProduct', 'handle' => 'test-product'];
        $exception = new ShopifyException('Test error', 0, null, $context);

        $this->assertEquals($context, $exception->getContext());
    }

    public function test_it_can_set_additional_context(): void
    {
        $exception = new ShopifyException('Test error', 0, null, ['key1' => 'value1']);
        $exception->setContext(['key2' => 'value2']);

        $context = $exception->getContext();
        $this->assertEquals('value1', $context['key1']);
        $this->assertEquals('value2', $context['key2']);
    }

    public function test_it_converts_to_array_with_standard_format(): void
    {
        $exception = new ShopifyException('Test error message');
        $array = $exception->toArray();

        $this->assertArrayHasKey('success', $array);
        $this->assertArrayHasKey('message', $array);
        $this->assertArrayHasKey('data', $array);
        $this->assertArrayHasKey('meta', $array);

        $this->assertFalse($array['success']);
        $this->assertEquals('Test error message', $array['message']);
        $this->assertIsArray($array['data']);
        $this->assertIsArray($array['meta']);
        $this->assertEquals('SHOPIFY_ERROR', $array['meta']['error_code']);
        $this->assertArrayHasKey('timestamp', $array['meta']);
    }

    public function test_it_supports_exception_chaining(): void
    {
        $previous = new \Exception('Previous exception');
        $exception = new ShopifyException('Test error', 0, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function test_it_has_default_http_status_code(): void
    {
        $exception = new ShopifyException();

        $this->assertEquals(500, $exception->getHttpStatusCode());
    }

    public function test_it_has_default_error_code(): void
    {
        $exception = new ShopifyException();

        $this->assertEquals('SHOPIFY_ERROR', $exception->getErrorCode());
    }

    public function test_it_has_empty_context_by_default(): void
    {
        $exception = new ShopifyException();

        $this->assertIsArray($exception->getContext());
        $this->assertEmpty($exception->getContext());
    }
}
