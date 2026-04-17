<?php

namespace Tests\Unit\Exceptions;

use App\Exceptions\ShopifyApiException;
use Tests\TestCase;

class ShopifyApiExceptionTest extends TestCase
{
    public function test_it_has_correct_http_status_code(): void
    {
        $exception = new ShopifyApiException();

        $this->assertEquals(500, $exception->getHttpStatusCode());
    }

    public function test_it_has_correct_error_code(): void
    {
        $exception = new ShopifyApiException();

        $this->assertEquals('API_ERROR', $exception->getErrorCode());
    }

    public function test_it_has_default_message(): void
    {
        $exception = new ShopifyApiException();

        $this->assertEquals('External API error', $exception->getMessage());
    }

    public function test_it_can_be_instantiated_with_custom_message(): void
    {
        $exception = new ShopifyApiException('Custom API error message');

        $this->assertEquals('Custom API error message', $exception->getMessage());
    }

    public function test_it_converts_to_array_with_correct_error_code(): void
    {
        $exception = new ShopifyApiException('API failed');
        $array = $exception->toArray();

        $this->assertFalse($array['success']);
        $this->assertEquals('API failed', $array['message']);
        $this->assertEquals('API_ERROR', $array['meta']['error_code']);
    }

    public function test_it_supports_context_data(): void
    {
        $context = ['endpoint' => '/admin/api/products', 'status' => 500];
        $exception = new ShopifyApiException('API error', 0, null, $context);

        $this->assertEquals($context, $exception->getContext());
    }
}
