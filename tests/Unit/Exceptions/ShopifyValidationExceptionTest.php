<?php

namespace Tests\Unit\Exceptions;

use App\Exceptions\ShopifyValidationException;
use Tests\TestCase;

class ShopifyValidationExceptionTest extends TestCase
{
    public function test_it_has_correct_http_status_code(): void
    {
        $exception = new ShopifyValidationException();

        $this->assertEquals(422, $exception->getHttpStatusCode());
    }

    public function test_it_has_correct_error_code(): void
    {
        $exception = new ShopifyValidationException();

        $this->assertEquals('VALIDATION_ERROR', $exception->getErrorCode());
    }

    public function test_it_has_default_message(): void
    {
        $exception = new ShopifyValidationException();

        $this->assertEquals('Validation failed', $exception->getMessage());
    }

    public function test_it_can_be_instantiated_with_validation_errors(): void
    {
        $errors = [
            'email' => ['The email field is required.'],
            'quantity' => ['The quantity must be at least 1.'],
        ];
        $exception = new ShopifyValidationException('Validation failed', $errors);

        $this->assertEquals($errors, $exception->getErrors());
    }

    public function test_it_has_empty_errors_by_default(): void
    {
        $exception = new ShopifyValidationException();

        $this->assertIsArray($exception->getErrors());
        $this->assertEmpty($exception->getErrors());
    }

    public function test_it_includes_errors_in_array_when_set(): void
    {
        $errors = [
            'variant_id' => ['The variant ID is invalid.'],
        ];
        $exception = new ShopifyValidationException('Validation failed', $errors);
        $array = $exception->toArray();

        $this->assertArrayHasKey('errors', $array['data']);
        $this->assertEquals($errors, $array['data']['errors']);
    }

    public function test_it_excludes_errors_from_array_when_empty(): void
    {
        $exception = new ShopifyValidationException();
        $array = $exception->toArray();

        $this->assertArrayNotHasKey('errors', $array['data']);
    }

    public function test_it_converts_to_array_with_correct_error_code(): void
    {
        $exception = new ShopifyValidationException('Invalid input');
        $array = $exception->toArray();

        $this->assertFalse($array['success']);
        $this->assertEquals('Invalid input', $array['message']);
        $this->assertEquals('VALIDATION_ERROR', $array['meta']['error_code']);
    }

    public function test_it_supports_context_data(): void
    {
        $context = ['operation' => 'addToCart', 'cart_id' => 'abc123'];
        $exception = new ShopifyValidationException('Validation failed', [], 0, null, $context);

        $this->assertEquals($context, $exception->getContext());
    }
}
