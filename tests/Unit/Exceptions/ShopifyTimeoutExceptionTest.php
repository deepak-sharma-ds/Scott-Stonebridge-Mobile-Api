<?php

namespace Tests\Unit\Exceptions;

use App\Exceptions\ShopifyTimeoutException;
use Tests\TestCase;

class ShopifyTimeoutExceptionTest extends TestCase
{
    public function test_it_has_correct_http_status_code(): void
    {
        $exception = new ShopifyTimeoutException();

        $this->assertEquals(504, $exception->getHttpStatusCode());
    }

    public function test_it_has_correct_error_code(): void
    {
        $exception = new ShopifyTimeoutException();

        $this->assertEquals('TIMEOUT', $exception->getErrorCode());
    }

    public function test_it_has_default_message(): void
    {
        $exception = new ShopifyTimeoutException();

        $this->assertEquals('Request timeout', $exception->getMessage());
    }

    public function test_it_can_be_instantiated_with_custom_message(): void
    {
        $exception = new ShopifyTimeoutException('Connection timed out after 30 seconds');

        $this->assertEquals('Connection timed out after 30 seconds', $exception->getMessage());
    }

    public function test_it_converts_to_array_with_correct_error_code(): void
    {
        $exception = new ShopifyTimeoutException('Request timeout');
        $array = $exception->toArray();

        $this->assertFalse($array['success']);
        $this->assertEquals('Request timeout', $array['message']);
        $this->assertEquals('TIMEOUT', $array['meta']['error_code']);
    }

    public function test_it_supports_context_data(): void
    {
        $context = ['timeout_seconds' => 30, 'endpoint' => '/admin/api/products'];
        $exception = new ShopifyTimeoutException('Timeout', 0, null, $context);

        $this->assertEquals($context, $exception->getContext());
    }
}
