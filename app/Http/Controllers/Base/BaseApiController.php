<?php

namespace App\Http\Controllers\Base;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Base API Controller
 * 
 * Provides standardized response methods for all API controllers.
 * Includes correlation ID handling and meta field population.
 * 
 * Requirements: 5.5, 9.1, 9.2, 9.6
 */
abstract class BaseApiController extends Controller
{
    /**
     * Return a standardized success response.
     * 
     * Format: {"success": true, "message": "", "data": {}, "meta": {}}
     * 
     * @param string $message Success message
     * @param mixed $data Response data
     * @param array $meta Additional metadata (merged with default meta)
     * @param int $statusCode HTTP status code (default: 200)
     * @return JsonResponse
     */
    protected function success(
        string $message,
        mixed $data = [],
        array $meta = [],
        int $statusCode = 200
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => $this->buildMeta($meta),
        ], $statusCode);
    }

    /**
     * Return a standardized error response.
     * 
     * Format: {"success": false, "message": "", "data": {}, "meta": {}}
     * 
     * @param string $message Error message
     * @param mixed $data Additional error data (optional)
     * @param array $meta Additional metadata (merged with default meta)
     * @param int $statusCode HTTP status code (default: 500)
     * @return JsonResponse
     */
    protected function error(
        string $message,
        mixed $data = [],
        array $meta = [],
        int $statusCode = 500
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => $data,
            'meta' => $this->buildMeta($meta),
        ], $statusCode);
    }

    /**
     * Build the meta field for responses.
     * 
     * Includes correlation ID, timestamp, and API version.
     * Merges with any additional metadata provided.
     * 
     * @param array $additionalMeta Additional metadata to include
     * @return array
     */
    protected function buildMeta(array $additionalMeta = []): array
    {
        $defaultMeta = [
            'correlation_id' => $this->getCorrelationId(),
            'timestamp' => now()->toIso8601String(),
            'version' => $this->getApiVersion(),
        ];

        return array_merge($defaultMeta, $additionalMeta);
    }

    /**
     * Get or generate a correlation ID for the current request.
     * 
     * Checks for existing correlation ID in request headers or attributes.
     * Generates a new UUID if none exists.
     * 
     * @return string
     */
    protected function getCorrelationId(): string
    {
        $request = request();

        // Check if correlation ID exists in request attributes (set by middleware)
        if ($request->attributes->has('correlation_id')) {
            return $request->attributes->get('correlation_id');
        }

        // Check if correlation ID exists in request headers
        if ($request->hasHeader('X-Correlation-ID')) {
            return $request->header('X-Correlation-ID');
        }

        // Generate a new correlation ID
        return (string) Str::uuid();
    }

    /**
     * Get the API version from the current route.
     * 
     * Extracts version from route prefix (e.g., /api/v1/products -> v1)
     * Defaults to 'v1' if version cannot be determined.
     * 
     * @return string
     */
    protected function getApiVersion(): string
    {
        $request = request();

        // Try to extract version from route prefix
        $path = $request->path();
        if (preg_match('#api/(v\d+)#', $path, $matches)) {
            return $matches[1];
        }

        // Check if version is set in route action
        $route = $request->route();
        if ($route && isset($route->action['version'])) {
            return $route->action['version'];
        }

        // Default to v1
        return 'v1';
    }

    /**
     * Return a paginated success response.
     * 
     * Includes pagination metadata in the meta field.
     * 
     * @param string $message Success message
     * @param mixed $data Response data
     * @param array $pagination Pagination data (next_cursor, has_more, total_count, etc.)
     * @param array $meta Additional metadata
     * @param int $statusCode HTTP status code (default: 200)
     * @return JsonResponse
     */
    protected function successWithPagination(
        string $message,
        mixed $data,
        array $pagination,
        array $meta = [],
        int $statusCode = 200
    ): JsonResponse {
        $meta['pagination'] = $pagination;

        return $this->success($message, $data, $meta, $statusCode);
    }

    /**
     * Return a validation error response.
     * 
     * @param string $message Error message
     * @param array $errors Validation errors
     * @param array $meta Additional metadata
     * @return JsonResponse
     */
    protected function validationError(
        string $message = 'Validation failed',
        array $errors = [],
        array $meta = []
    ): JsonResponse {
        return $this->error($message, ['errors' => $errors], $meta, 422);
    }

    /**
     * Return a not found error response.
     * 
     * @param string $message Error message
     * @param array $meta Additional metadata
     * @return JsonResponse
     */
    protected function notFound(
        string $message = 'Resource not found',
        array $meta = []
    ): JsonResponse {
        return $this->error($message, [], $meta, 404);
    }

    /**
     * Return an unauthorized error response.
     * 
     * @param string $message Error message
     * @param array $meta Additional metadata
     * @return JsonResponse
     */
    protected function unauthorized(
        string $message = 'Unauthorized',
        array $meta = []
    ): JsonResponse {
        return $this->error($message, [], $meta, 401);
    }

    /**
     * Return a forbidden error response.
     * 
     * @param string $message Error message
     * @param array $meta Additional metadata
     * @return JsonResponse
     */
    protected function forbidden(
        string $message = 'Forbidden',
        array $meta = []
    ): JsonResponse {
        return $this->error($message, [], $meta, 403);
    }

    /**
     * Return a rate limit error response.
     * 
     * @param string $message Error message
     * @param int|null $retryAfter Seconds until rate limit resets
     * @param array $meta Additional metadata
     * @return JsonResponse
     */
    protected function rateLimitExceeded(
        string $message = 'Rate limit exceeded',
        ?int $retryAfter = null,
        array $meta = []
    ): JsonResponse {
        if ($retryAfter !== null) {
            $meta['retry_after'] = $retryAfter;
        }

        $response = $this->error($message, [], $meta, 429);

        if ($retryAfter !== null) {
            $response->header('Retry-After', $retryAfter);
        }

        return $response;
    }
}
