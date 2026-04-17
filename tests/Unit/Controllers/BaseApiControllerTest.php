<?php

namespace Tests\Unit\Controllers;

use App\Http\Controllers\Base\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Unit tests for BaseApiController
 * 
 * Tests standardized response methods, correlation ID handling, and meta field population.
 * 
 * Requirements: 5.5, 9.1, 9.2, 9.6
 */
class BaseApiControllerTest extends TestCase
{
    private TestableBaseApiController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new TestableBaseApiController();
    }

    /** @test */
    public function it_returns_success_response_with_correct_structure()
    {
        $response = $this->controller->testSuccess('Operation successful', ['user' => 'John']);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = $response->getData(true);

        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('meta', $data);

        $this->assertTrue($data['success']);
        $this->assertEquals('Operation successful', $data['message']);
        $this->assertEquals(['user' => 'John'], $data['data']);
    }

    /** @test */
    public function it_returns_error_response_with_correct_structure()
    {
        $response = $this->controller->testError('Operation failed', ['error' => 'details'], [], 500);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(500, $response->getStatusCode());

        $data = $response->getData(true);

        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('meta', $data);

        $this->assertFalse($data['success']);
        $this->assertEquals('Operation failed', $data['message']);
        $this->assertEquals(['error' => 'details'], $data['data']);
    }

    /** @test */
    public function it_includes_correlation_id_in_meta()
    {
        $response = $this->controller->testSuccess('Test', []);
        $data = $response->getData(true);

        $this->assertArrayHasKey('correlation_id', $data['meta']);
        $this->assertNotEmpty($data['meta']['correlation_id']);
        $this->assertIsString($data['meta']['correlation_id']);
    }

    /** @test */
    public function it_includes_timestamp_in_meta()
    {
        $response = $this->controller->testSuccess('Test', []);
        $data = $response->getData(true);

        $this->assertArrayHasKey('timestamp', $data['meta']);
        $this->assertNotEmpty($data['meta']['timestamp']);
        $this->assertIsString($data['meta']['timestamp']);
    }

    /** @test */
    public function it_includes_version_in_meta()
    {
        $response = $this->controller->testSuccess('Test', []);
        $data = $response->getData(true);

        $this->assertArrayHasKey('version', $data['meta']);
        $this->assertEquals('v1', $data['meta']['version']);
    }

    /** @test */
    public function it_uses_correlation_id_from_request_header()
    {
        $correlationId = 'test-correlation-id-123';
        
        $request = Request::create('/test', 'GET');
        $request->headers->set('X-Correlation-ID', $correlationId);
        $this->app->instance('request', $request);

        $response = $this->controller->testSuccess('Test', []);
        $data = $response->getData(true);

        $this->assertEquals($correlationId, $data['meta']['correlation_id']);
    }

    /** @test */
    public function it_uses_correlation_id_from_request_attributes()
    {
        $correlationId = 'test-correlation-id-456';
        
        $request = Request::create('/test', 'GET');
        $request->attributes->set('correlation_id', $correlationId);
        $this->app->instance('request', $request);

        $response = $this->controller->testSuccess('Test', []);
        $data = $response->getData(true);

        $this->assertEquals($correlationId, $data['meta']['correlation_id']);
    }

    /** @test */
    public function it_merges_additional_meta_data()
    {
        $additionalMeta = [
            'custom_field' => 'custom_value',
            'another_field' => 123,
        ];

        $response = $this->controller->testSuccess('Test', [], $additionalMeta);
        $data = $response->getData(true);

        $this->assertArrayHasKey('custom_field', $data['meta']);
        $this->assertArrayHasKey('another_field', $data['meta']);
        $this->assertEquals('custom_value', $data['meta']['custom_field']);
        $this->assertEquals(123, $data['meta']['another_field']);
    }

    /** @test */
    public function it_returns_paginated_response_with_pagination_meta()
    {
        $pagination = [
            'next_cursor' => 'abc123',
            'has_more' => true,
            'total_count' => 100,
        ];

        $response = $this->controller->testSuccessWithPagination(
            'Products fetched',
            ['products' => []],
            $pagination
        );

        $data = $response->getData(true);

        $this->assertArrayHasKey('pagination', $data['meta']);
        $this->assertEquals($pagination, $data['meta']['pagination']);
    }

    /** @test */
    public function it_returns_validation_error_response()
    {
        $errors = [
            'email' => ['The email field is required.'],
            'password' => ['The password must be at least 8 characters.'],
        ];

        $response = $this->controller->testValidationError('Validation failed', $errors);

        $this->assertEquals(422, $response->getStatusCode());

        $data = $response->getData(true);

        $this->assertFalse($data['success']);
        $this->assertEquals('Validation failed', $data['message']);
        $this->assertArrayHasKey('errors', $data['data']);
        $this->assertEquals($errors, $data['data']['errors']);
    }

    /** @test */
    public function it_returns_not_found_response()
    {
        $response = $this->controller->testNotFound('Product not found');

        $this->assertEquals(404, $response->getStatusCode());

        $data = $response->getData(true);

        $this->assertFalse($data['success']);
        $this->assertEquals('Product not found', $data['message']);
    }

    /** @test */
    public function it_returns_unauthorized_response()
    {
        $response = $this->controller->testUnauthorized('Invalid credentials');

        $this->assertEquals(401, $response->getStatusCode());

        $data = $response->getData(true);

        $this->assertFalse($data['success']);
        $this->assertEquals('Invalid credentials', $data['message']);
    }

    /** @test */
    public function it_returns_forbidden_response()
    {
        $response = $this->controller->testForbidden('Access denied');

        $this->assertEquals(403, $response->getStatusCode());

        $data = $response->getData(true);

        $this->assertFalse($data['success']);
        $this->assertEquals('Access denied', $data['message']);
    }

    /** @test */
    public function it_returns_rate_limit_response_with_retry_after()
    {
        $retryAfter = 60;
        $response = $this->controller->testRateLimitExceeded('Too many requests', $retryAfter);

        $this->assertEquals(429, $response->getStatusCode());

        $data = $response->getData(true);

        $this->assertFalse($data['success']);
        $this->assertEquals('Too many requests', $data['message']);
        $this->assertArrayHasKey('retry_after', $data['meta']);
        $this->assertEquals($retryAfter, $data['meta']['retry_after']);
        $this->assertEquals($retryAfter, $response->headers->get('Retry-After'));
    }

    /** @test */
    public function it_extracts_api_version_from_route_path()
    {
        $request = Request::create('/api/v2/products', 'GET');
        $this->app->instance('request', $request);

        $response = $this->controller->testSuccess('Test', []);
        $data = $response->getData(true);

        $this->assertEquals('v2', $data['meta']['version']);
    }

    /** @test */
    public function it_defaults_to_v1_when_version_not_in_path()
    {
        $request = Request::create('/products', 'GET');
        $this->app->instance('request', $request);

        $response = $this->controller->testSuccess('Test', []);
        $data = $response->getData(true);

        $this->assertEquals('v1', $data['meta']['version']);
    }

    /** @test */
    public function it_allows_custom_status_codes_for_success()
    {
        $response = $this->controller->testSuccess('Created', ['id' => 1], [], 201);

        $this->assertEquals(201, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertTrue($data['success']);
    }

    /** @test */
    public function it_allows_custom_status_codes_for_error()
    {
        $response = $this->controller->testError('Bad request', [], [], 400);

        $this->assertEquals(400, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertFalse($data['success']);
    }

    /** @test */
    public function it_handles_empty_data_in_success_response()
    {
        $response = $this->controller->testSuccess('Success', []);

        $data = $response->getData(true);

        $this->assertEquals([], $data['data']);
    }

    /** @test */
    public function it_handles_empty_data_in_error_response()
    {
        $response = $this->controller->testError('Error', [], [], 500);

        $data = $response->getData(true);

        $this->assertEquals([], $data['data']);
    }
}

/**
 * Testable implementation of BaseApiController for testing purposes.
 * Exposes protected methods as public for testing.
 */
class TestableBaseApiController extends BaseApiController
{
    public function testSuccess(string $message, mixed $data = [], array $meta = [], int $statusCode = 200): JsonResponse
    {
        return $this->success($message, $data, $meta, $statusCode);
    }

    public function testError(string $message, mixed $data = [], array $meta = [], int $statusCode = 500): JsonResponse
    {
        return $this->error($message, $data, $meta, $statusCode);
    }

    public function testSuccessWithPagination(string $message, mixed $data, array $pagination, array $meta = [], int $statusCode = 200): JsonResponse
    {
        return $this->successWithPagination($message, $data, $pagination, $meta, $statusCode);
    }

    public function testValidationError(string $message = 'Validation failed', array $errors = [], array $meta = []): JsonResponse
    {
        return $this->validationError($message, $errors, $meta);
    }

    public function testNotFound(string $message = 'Resource not found', array $meta = []): JsonResponse
    {
        return $this->notFound($message, $meta);
    }

    public function testUnauthorized(string $message = 'Unauthorized', array $meta = []): JsonResponse
    {
        return $this->unauthorized($message, $meta);
    }

    public function testForbidden(string $message = 'Forbidden', array $meta = []): JsonResponse
    {
        return $this->forbidden($message, $meta);
    }

    public function testRateLimitExceeded(string $message = 'Rate limit exceeded', ?int $retryAfter = null, array $meta = []): JsonResponse
    {
        return $this->rateLimitExceeded($message, $retryAfter, $meta);
    }
}
