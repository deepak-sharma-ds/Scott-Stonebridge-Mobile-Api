<?php

namespace Tests\Unit\Exceptions;

use App\Exceptions\ShopifyNotFoundException;
use Tests\TestCase;

class ShopifyNotFoundExceptionTest extends TestCase
{
    public function test_it_has_correct_http_status_code(): void
    {
        $exception = new ShopifyNotFoundException();

        $this->assertEquals(404, $exception->getHttpStatusCode());
    }

    public function test_it_has_correct_error_code(): void
    {
        $exception = new ShopifyNotFoundException();

        $this->assertEquals('NOT_FOUND', $exception->getErrorCode());
    }

    public function test_it_has_default_message(): void
    {
        $exception = new ShopifyNotFoundException();

        $this->assertEquals('Resource not found', $exception->getMessage());
    }

    public function test_it_can_be_instantiated_with_custom_message(): void
    {
        $exception = new ShopifyNotFoundException('Product not found');

        $this->assertEquals('Product not found', $exception->getMessage());
    }

    public function test_it_converts_to_array_with_correct_error_code(): void
    {
        $exception = new ShopifyNotFoundException('Cart not found');
        $array = $exception->toArray();

        $this->assertFalse($array['success']);
        $this->assertEquals('Cart not found', $array['message']);
        $this->assertEquals('NOT_FOUND', $array['meta']['error_code']);
    }

    public function test_it_supports_context_data(): void
    {
        $context = ['resource_type' => 'product', 'handle' => 'missing-product'];
        $exception = new ShopifyNotFoundException('Not found', 0, null, $context);

        $this->assertEquals($context, $exception->getContext());
    }
}
