<?php

namespace App\Clients\Base;

use App\Clients\Concerns\HasCircuitBreaker;
use App\Clients\Concerns\HasRetryLogic;
use App\Contracts\Shopify\ShopifyClientInterface;
use App\Exceptions\ShopifyApiException;
use App\Exceptions\ShopifyTimeoutException;
use App\Facades\GraphQLLoader;
use App\Traits\CacheWithFallback;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

abstract class BaseShopifyClient implements ShopifyClientInterface
{
    use HasRetryLogic, HasCircuitBreaker, CacheWithFallback;

    protected Client $httpClient;
    protected ?int $cacheTtl = null;
    protected array $cacheTags = [];
    protected float $lastRequestDuration = 0.0;
    protected ?int $lastRequestCost = null;

    public function __construct()
    {
        $this->httpClient = new Client([
            'timeout' => config('shopify.http.timeout', 30),
            'connect_timeout' => config('shopify.http.connect_timeout', 10),
            'http_errors' => false, // Handle errors manually
        ]);
    }

    /**
     * Get the API endpoint URL
     */
    abstract protected function getEndpoint(): string;

    /**
     * Get the authentication headers
     */
    abstract protected function getAuthHeaders(): array;

    /**
     * Get the API type for logging (admin or storefront)
     */
    abstract protected function getApiType(): string;

    /**
     * Execute a GraphQL query
     */
    public function query(string $queryPath, array $variables = []): array
    {
        $correlationId = request()->header('X-Correlation-ID') ?? Str::uuid()->toString();
        
        // Check cache first if caching is enabled
        if ($this->cacheTtl !== null) {
            $cacheKey = $this->getCacheKey($queryPath, $variables);
            
            try {
                // Try to get from cache with tags
                $cached = !empty($this->cacheTags) 
                    ? Cache::tags($this->cacheTags)->get($cacheKey)
                    : Cache::get($cacheKey);
                
                if ($cached !== null) {
                    Log::channel('shopify')->info('Cache hit', [
                        'correlation_id' => $correlationId,
                        'query_path' => $queryPath,
                        'cache_key' => $cacheKey,
                    ]);
                    
                    return $cached;
                }
            } catch (\BadMethodCallException $e) {
                // Fallback to simple cache without tags
                $cached = Cache::get($cacheKey);
                
                if ($cached !== null) {
                    Log::channel('shopify')->info('Cache hit (fallback)', [
                        'correlation_id' => $correlationId,
                        'query_path' => $queryPath,
                        'cache_key' => $cacheKey,
                    ]);
                    
                    return $cached;
                }
            }
        }

        // Load GraphQL query
        $query = GraphQLLoader::load($queryPath);

        // Prepare request payload
        $payload = [
            'query' => $query,
        ];

        if (!empty($variables)) {
            $payload['variables'] = $variables;
        }

        // Execute request with retry logic if configured
        $response = $this->executeWithRetry(
            fn($attempt) => $this->executeRequest($payload, $correlationId, $queryPath, $attempt),
            null,
            null,
            $queryPath
        );

        // Cache response if caching is enabled
        if ($this->cacheTtl !== null && isset($response['data'])) {
            $cacheKey = $this->getCacheKey($queryPath, $variables);
            
            try {
                // Try to cache with tags
                if (!empty($this->cacheTags)) {
                    Cache::tags($this->cacheTags)->put($cacheKey, $response, $this->cacheTtl);
                } else {
                    Cache::put($cacheKey, $response, $this->cacheTtl);
                }
            } catch (\BadMethodCallException $e) {
                // Fallback to simple cache without tags
                Cache::put($cacheKey, $response, $this->cacheTtl);
            }
        }

        // Reset per-request configuration
        $this->resetRequestConfig();

        return $response;
    }

    /**
     * Execute the actual HTTP request
     */
    protected function executeRequest(
        array $payload,
        string $correlationId,
        string $queryPath,
        int $attempt
    ): array {
        $startTime = microtime(true);

        try {
            $headers = array_merge(
                $this->getAuthHeaders(),
                [
                    'Content-Type' => 'application/json',
                    'X-Correlation-ID' => $correlationId,
                ]
            );

            $response = $this->httpClient->post($this->getEndpoint(), [
                'headers' => $headers,
                'json' => $payload,
            ]);

            $duration = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
            $this->lastRequestDuration = $duration;

            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);

            // Extract GraphQL cost if available
            if (isset($body['extensions']['cost']['requestedQueryCost'])) {
                $this->lastRequestCost = $body['extensions']['cost']['requestedQueryCost'];
            }

            // Log request
            $this->logRequest($correlationId, $queryPath, $duration, $statusCode, $attempt);

            // Handle HTTP errors
            if ($statusCode >= 500) {
                throw new ShopifyApiException(
                    "Shopify API returned {$statusCode} status code",
                    $statusCode
                );
            }

            if ($statusCode === 429) {
                throw new \App\Exceptions\ShopifyRateLimitException(
                    'Rate limit exceeded',
                    429
                );
            }

            if ($statusCode >= 400) {
                throw new ShopifyApiException(
                    "Shopify API error: " . ($body['errors'][0]['message'] ?? 'Unknown error'),
                    $statusCode
                );
            }

            // Handle GraphQL errors
            if (!empty($body['errors'])) {
                $errorMessage = is_array($body['errors'])
                    ? json_encode($body['errors'], JSON_UNESCAPED_SLASHES)
                    : $body['errors'];

                throw new ShopifyApiException(
                    "Shopify GraphQL error: {$errorMessage}",
                    $statusCode
                );
            }

            // Validate response structure
            if (!isset($body['data'])) {
                throw new ShopifyApiException(
                    "Invalid Shopify API response: missing 'data' field",
                    $statusCode
                );
            }

            return $body;

        } catch (RequestException $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            $this->lastRequestDuration = $duration;

            $this->logError($correlationId, $queryPath, $duration, $e);

            if ($e->getCode() === CURLE_OPERATION_TIMEDOUT || $e->getCode() === CURLE_OPERATION_TIMEOUTED) {
                throw new ShopifyTimeoutException(
                    'Request timeout',
                    0,
                    $e
                );
            }

            throw new ShopifyApiException(
                'HTTP request failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Enable caching for the next request
     */
    public function withCache(int $ttl, array $tags = []): self
    {
        $this->cacheTtl = $ttl;
        $this->cacheTags = $tags;
        return $this;
    }

    /**
     * Get the duration of the last request in milliseconds
     */
    public function getLastRequestDuration(): float
    {
        return $this->lastRequestDuration;
    }

    /**
     * Get the GraphQL cost of the last request
     */
    public function getLastRequestCost(): ?int
    {
        return $this->lastRequestCost;
    }

    /**
     * Generate cache key for query
     */
    protected function getCacheKey(string $queryPath, array $variables): string
    {
        $variablesHash = md5(json_encode($variables));
        return "shopify:{$this->getApiType()}:{$queryPath}:{$variablesHash}";
    }

    /**
     * Reset per-request configuration
     */
    protected function resetRequestConfig(): void
    {
        $this->resetRetryConfig();
        $this->resetCircuitBreakerConfig();
        $this->cacheTtl = null;
        $this->cacheTags = [];
    }

    /**
     * Log successful request
     */
    protected function logRequest(
        string $correlationId,
        string $queryPath,
        float $duration,
        int $statusCode,
        int $attempt
    ): void {
        Log::channel('shopify')->info('Shopify API request', [
            'correlation_id' => $correlationId,
            'api_type' => $this->getApiType(),
            'query_path' => $queryPath,
            'duration_ms' => round($duration, 2),
            'status_code' => $statusCode,
            'attempt' => $attempt,
            'cost' => $this->lastRequestCost,
        ]);

        // Also log to performance channel
        Log::channel('performance')->info('API request performance', [
            'correlation_id' => $correlationId,
            'api_type' => $this->getApiType(),
            'query_path' => $queryPath,
            'duration_ms' => round($duration, 2),
        ]);
    }

    /**
     * Log request error
     */
    protected function logError(
        string $correlationId,
        string $queryPath,
        float $duration,
        \Exception $exception
    ): void {
        Log::channel('error')->error('Shopify API request failed', [
            'correlation_id' => $correlationId,
            'api_type' => $this->getApiType(),
            'query_path' => $queryPath,
            'duration_ms' => round($duration, 2),
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
