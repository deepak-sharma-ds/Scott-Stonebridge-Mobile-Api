<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * ApiLoggingMiddleware
 * 
 * Logs all API requests and responses with correlation ID, duration, and status.
 * Uses structured JSON format for log entries.
 * 
 * Requirements: 10.2, 10.5, 15.5
 */
class ApiLoggingMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Record start time
        $startTime = microtime(true);

        // Get correlation ID from request (should be set by CorrelationIdMiddleware)
        $correlationId = $request->attributes->get('correlation_id') 
            ?? $request->input('correlation_id')
            ?? 'unknown';

        // Log incoming request
        $this->logRequest($request, $correlationId);

        // Process the request
        $response = $next($request);

        // Calculate duration
        $duration = round((microtime(true) - $startTime) * 1000, 2); // milliseconds

        // Log response
        $this->logResponse($request, $response, $correlationId, $duration);

        return $response;
    }

    /**
     * Log incoming request
     */
    protected function logRequest(Request $request, string $correlationId): void
    {
        $log = Log::channel('api');

        $logData = [
            'type' => 'request',
            'correlation_id' => $correlationId,
            'timestamp' => now()->toIso8601String(),
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'headers' => $this->sanitizeHeaders($request->headers->all()),
            'query_params' => $request->query->all(),
            'body_size' => strlen($request->getContent()),
        ];

        // Add authenticated user info if available
        if ($customerId = $request->attributes->get('shopify_customer_id')) {
            $logData['customer_id'] = $customerId;
        }

        // Add currency if available
        if ($currency = $request->attributes->get('currency')) {
            $logData['currency'] = $currency;
        }

        $log->info('API Request', $logData);
    }

    /**
     * Log response
     */
    protected function logResponse(
        Request $request,
        Response $response,
        string $correlationId,
        float $duration
    ): void {
        $log = Log::channel('api');

        $logData = [
            'type' => 'response',
            'correlation_id' => $correlationId,
            'timestamp' => now()->toIso8601String(),
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $duration,
            'response_size' => strlen($response->getContent()),
        ];

        // Add performance warning for slow requests
        if ($duration > 1000) {
            $logData['performance_warning'] = 'slow_request';
        }

        // Determine log level based on status code
        $level = $this->getLogLevel($response->getStatusCode());

        $log->log($level, 'API Response', $logData);

        // Log to performance channel for metrics
        if ($duration > 500) {
            Log::channel('performance')->info('API Performance', [
                'correlation_id' => $correlationId,
                'path' => $request->path(),
                'method' => $request->method(),
                'duration_ms' => $duration,
                'status' => $response->getStatusCode(),
            ]);
        }
    }

    /**
     * Sanitize headers to remove sensitive information
     */
    protected function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = [
            'authorization',
            'cookie',
            'x-api-key',
            'x-auth-token',
        ];

        $sanitized = [];

        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);
            
            if (in_array($lowerKey, $sensitiveHeaders, true)) {
                $sanitized[$key] = ['***REDACTED***'];
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Get log level based on HTTP status code
     */
    protected function getLogLevel(int $statusCode): string
    {
        return match (true) {
            $statusCode >= 500 => 'error',
            $statusCode >= 400 => 'warning',
            default => 'info',
        };
    }
}
