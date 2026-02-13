<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RateLimitMiddleware
 * 
 * Implements rate limiting per IP/user to prevent API abuse.
 * Returns 429 status when limit exceeded.
 * 
 * Requirements: 8.6, 15.3
 */
class RateLimitMiddleware
{
    /**
     * The rate limiter instance.
     */
    protected RateLimiter $limiter;

    /**
     * Create a new middleware instance.
     */
    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get rate limit configuration
        $maxAttempts = config('shopify.rate_limit.max_attempts', 60);
        $decayMinutes = config('shopify.rate_limit.decay_minutes', 1);
        $enabled = config('shopify.rate_limit.enabled', true);

        // Skip rate limiting if disabled
        if (!$enabled) {
            return $next($request);
        }

        // Generate rate limit key based on IP and optional user identifier
        $key = $this->resolveRequestSignature($request);

        // Check if rate limit exceeded
        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            return $this->buildRateLimitResponse($key, $maxAttempts);
        }

        // Increment the rate limiter
        $this->limiter->hit($key, $decayMinutes * 60);

        // Process the request
        $response = $next($request);

        // Add rate limit headers to response
        return $this->addRateLimitHeaders(
            $response,
            $maxAttempts,
            $this->calculateRemainingAttempts($key, $maxAttempts)
        );
    }

    /**
     * Resolve the request signature for rate limiting.
     */
    protected function resolveRequestSignature(Request $request): string
    {
        // Use authenticated user ID if available, otherwise use IP
        $userId = $request->attributes->get('shopify_customer_id') 
            ?? $request->input('shopify_customer_data.id')
            ?? null;

        if ($userId) {
            return 'rate_limit:user:' . sha1($userId);
        }

        // Fall back to IP-based rate limiting for guest users
        return 'rate_limit:ip:' . sha1($request->ip());
    }

    /**
     * Calculate remaining attempts.
     */
    protected function calculateRemainingAttempts(string $key, int $maxAttempts): int
    {
        $attempts = $this->limiter->attempts($key);
        return max(0, $maxAttempts - $attempts);
    }

    /**
     * Build rate limit exceeded response.
     */
    protected function buildRateLimitResponse(string $key, int $maxAttempts): Response
    {
        $retryAfter = $this->limiter->availableIn($key);

        $response = response()->json([
            'success' => false,
            'message' => 'Too many requests. Please try again later.',
            'data' => [],
            'meta' => [
                'error_code' => 'RATE_LIMIT_EXCEEDED',
                'retry_after' => $retryAfter,
                'retry_after_human' => $this->formatRetryAfter($retryAfter),
            ],
        ], 429);

        return $this->addRateLimitHeaders($response, $maxAttempts, 0, $retryAfter);
    }

    /**
     * Add rate limit headers to response.
     */
    protected function addRateLimitHeaders(
        Response $response,
        int $maxAttempts,
        int $remainingAttempts,
        ?int $retryAfter = null
    ): Response {
        $response->headers->set('X-RateLimit-Limit', $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', $remainingAttempts);

        if ($retryAfter !== null) {
            $response->headers->set('Retry-After', $retryAfter);
            $response->headers->set('X-RateLimit-Reset', now()->addSeconds($retryAfter)->timestamp);
        }

        return $response;
    }

    /**
     * Format retry after seconds to human-readable format.
     */
    protected function formatRetryAfter(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' second' . ($seconds !== 1 ? 's' : '');
        }

        $minutes = ceil($seconds / 60);
        return $minutes . ' minute' . ($minutes !== 1 ? 's' : '');
    }
}
