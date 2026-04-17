<?php

namespace App\Http\Middleware;

use App\Contracts\Cache\CacheStrategyInterface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * ResponseCacheMiddleware
 * 
 * Caches GET responses with appropriate TTL using cache tags from CacheStrategy.
 * Skips caching for authenticated requests.
 * 
 * Requirements: 15.6
 */
class ResponseCacheMiddleware
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected CacheStrategyInterface $cacheStrategy
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only cache GET requests
        if (!$request->isMethod('GET')) {
            return $next($request);
        }

        // Skip caching if disabled in config
        if (!config('shopify.cache.enabled', true)) {
            return $next($request);
        }

        // Skip caching for authenticated requests (to avoid leaking user data)
        if ($this->isAuthenticatedRequest($request)) {
            return $next($request);
        }

        // Generate cache key based on request
        $cacheKey = $this->generateCacheKey($request);
        $operation = $this->extractOperation($request);

        // Check if this operation should be cached
        if (!$this->cacheStrategy->shouldCache($operation)) {
            return $next($request);
        }

        // Try to get cached response
        $cachedResponse = $this->getCachedResponse($cacheKey);
        
        if ($cachedResponse !== null) {
            return $this->buildResponseFromCache($cachedResponse);
        }

        // Process the request
        $response = $next($request);

        // Only cache successful responses
        if ($this->shouldCacheResponse($response)) {
            $this->cacheResponse($request, $response, $cacheKey, $operation);
        }

        return $response;
    }

    /**
     * Check if request is authenticated
     */
    protected function isAuthenticatedRequest(Request $request): bool
    {
        // Check for bearer token
        if ($request->bearerToken()) {
            return true;
        }

        // Check for customer data in request (set by auth middleware)
        if ($request->attributes->has('shopify_customer_id')) {
            return true;
        }

        return false;
    }

    /**
     * Generate cache key for request
     */
    protected function generateCacheKey(Request $request): string
    {
        $params = [
            'path' => $request->path(),
            'query' => $request->query->all(),
            'currency' => $request->attributes->get('currency', config('shopify.currency', 'GBP')),
        ];

        return $this->cacheStrategy->getCacheKey('response', $params);
    }

    /**
     * Extract operation name from request path
     */
    protected function extractOperation(Request $request): string
    {
        $path = $request->path();

        // Map paths to operations
        if (str_contains($path, 'products')) {
            return 'product';
        }
        if (str_contains($path, 'collections')) {
            return 'collection';
        }
        if (str_contains($path, 'cart')) {
            return 'cart';
        }

        return 'default';
    }

    /**
     * Get cached response
     */
    protected function getCachedResponse(string $cacheKey): ?array
    {
        return Cache::get($cacheKey);
    }

    /**
     * Check if response should be cached
     */
    protected function shouldCacheResponse(Response $response): bool
    {
        $statusCode = $response->getStatusCode();

        // Only cache successful responses (2xx)
        if ($statusCode < 200 || $statusCode >= 300) {
            return false;
        }

        // Don't cache empty responses
        if (empty($response->getContent())) {
            return false;
        }

        return true;
    }

    /**
     * Cache the response
     */
    protected function cacheResponse(
        Request $request,
        Response $response,
        string $cacheKey,
        string $operation
    ): void {
        $params = [
            'currency' => $request->attributes->get('currency', config('shopify.currency', 'GBP')),
        ];

        // Get TTL and tags from cache strategy
        $ttl = $this->cacheStrategy->getCacheTTL($operation);
        $tags = $this->cacheStrategy->getCacheTags($operation, $params);

        // Prepare response data for caching
        $cacheData = [
            'content' => $response->getContent(),
            'status' => $response->getStatusCode(),
            'headers' => $response->headers->all(),
            'cached_at' => now()->toIso8601String(),
        ];

        // Cache with tags if supported by cache driver
        if ($this->supportsTags()) {
            Cache::tags($tags)->put($cacheKey, $cacheData, $ttl);
        } else {
            Cache::put($cacheKey, $cacheData, $ttl);
        }
    }

    /**
     * Build response from cached data
     */
    protected function buildResponseFromCache(array $cachedData): Response
    {
        $response = response($cachedData['content'], $cachedData['status']);

        // Restore headers (excluding some that should be fresh)
        $excludeHeaders = ['date', 'age', 'expires'];
        
        foreach ($cachedData['headers'] as $key => $values) {
            if (!in_array(strtolower($key), $excludeHeaders, true)) {
                $response->headers->set($key, $values);
            }
        }

        // Add cache headers
        $response->headers->set('X-Cache', 'HIT');
        $response->headers->set('X-Cache-Date', $cachedData['cached_at']);

        return $response;
    }

    /**
     * Check if cache driver supports tags
     */
    protected function supportsTags(): bool
    {
        $driver = config('cache.default');
        
        // Redis and Memcached support tags
        return in_array($driver, ['redis', 'memcached'], true);
    }
}
