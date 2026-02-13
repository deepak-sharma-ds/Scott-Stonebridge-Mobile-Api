<?php

namespace Tests\Unit\Exceptions;

use App\Exceptions\ShopifyRateLimitException;
use Tests\TestCase;

class ShopifyRateLimitExceptionTest extends TestCase
{
    public function test_it_has_correct_http_status_code(): void
    {
        $exception = new ShopifyRateLimitException();

        $this->assertEquals(429, $exception->getHttpStatusCode());
    }

    public function test_it_has_correct_error_code(): void
    {
        $exception = new ShopifyRateLimitException();

        $this->assertEquals('RATE_LIMIT', $exception->getErrorCode());
    }

    public function test_it_has_default_message(): void
    {
        $exception = new ShopifyRateLimitException();

        $this->assertEquals('Rate limit exceeded', $exception->getMessage());
    }

    public function test_it_can_be_instantiated_with_retry_after(): void
    {
        $exception = new ShopifyRateLimitException('Too many requests', 60);

        $this->assertEquals(60, $exception->getRetryAfter());
    }

    public function test_it_can_have_null_retry_after(): void
    {
        $exception = new ShopifyRateLimitException();

        $this->assertNull($exception->getRetryAfter());
    }

    public function test_it_includes_retry_after_in_array_when_set(): void
    {
        $exception = new ShopifyRateLimitException('Rate limit exceeded', 120);
        $array = $exception->toArray();

        $this->assertArrayHasKey('retry_after', $array['meta']);
        $this->assertEquals(120, $array['meta']['retry_after']);
    }

    public function test_it_excludes_retry_after_from_array_when_null(): void
    {
        $exception = new ShopifyRateLimitException();
        $array = $exception->toArray();

        $this->assertArrayNotHasKey('retry_after', $array['meta']);
    }

    public function test_it_converts_to_array_with_correct_error_code(): void
    {
        $exception = new ShopifyRateLimitException('Too many requests');
        $array = $exception->toArray();

        $this->assertFalse($array['success']);
        $this->assertEquals('Too many requests', $array['message']);
        $this->assertEquals('RATE_LIMIT', $array['meta']['error_code']);
    }

    public function test_it_supports_context_data(): void
    {
        $context = ['limit' => 40, 'remaining' => 0];
        $exception = new ShopifyRateLimitException('Rate limit', 60, 0, null, $context);

        $this->assertEquals($context, $exception->getContext());
    }
}
