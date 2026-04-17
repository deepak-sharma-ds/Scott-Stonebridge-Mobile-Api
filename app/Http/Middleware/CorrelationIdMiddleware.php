<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * CorrelationIdMiddleware
 * 
 * Generates or extracts correlation ID from request headers for request tracking.
 * Adds correlation ID to request context and response headers.
 * 
 * Requirements: 8.7, 15.1
 */
class CorrelationIdMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Extract correlation ID from request header or generate a new one
        $correlationId = $request->header('X-Correlation-ID') 
            ?? $request->header('X-Request-ID')
            ?? (string) Str::uuid();

        // Add correlation ID to request attributes for use in controllers/services
        $request->attributes->set('correlation_id', $correlationId);

        // Also make it available via request merge for easier access
        $request->merge(['correlation_id' => $correlationId]);

        // Process the request
        $response = $next($request);

        // Add correlation ID to response headers
        $response->headers->set('X-Correlation-ID', $correlationId);

        return $response;
    }
}
