<?php

namespace Tests\Unit\Exceptions;

use App\Exceptions\ShopifyAuthException;
use Tests\TestCase;

class ShopifyAuthExceptionTest extends TestCase
{
    public function test_it_has_correct_http_status_code(): void
    {
        $exception = new ShopifyAuthException();

        $this->assertEquals(401, $exception->getHttpStatusCode());
    }

    public function test_it_has_correct_error_code(): void
    {
        $exception = new ShopifyAuthException();

        $this->assertEquals('AUTH_FAILED', $exception->getErrorCode());
    }

    public function test_it_has_default_message(): void
    {
        $exception = new ShopifyAuthException();

        $this->assertEquals('Authentication failed', $exception->getMessage());
    }

    public function test_it_can_be_instantiated_with_custom_message(): void
    {
        $exception = new ShopifyAuthException('Invalid access token');

        $this->assertEquals('Invalid access token', $exception->getMessage());
    }

    public function test_it_converts_to_array_with_correct_error_code(): void
    {
        $exception = new ShopifyAuthException('Token expired');
        $array = $exception->toArray();

        $this->assertFalse($array['success']);
        $this->assertEquals('Token expired', $array['message']);
        $this->assertEquals('AUTH_FAILED', $array['meta']['error_code']);
    }

    public function test_it_supports_context_data(): void
    {
        $context = ['token' => 'redacted', 'user_id' => null];
        $exception = new ShopifyAuthException('Auth failed', 0, null, $context);

        $this->assertEquals($context, $exception->getContext());
    }
}
