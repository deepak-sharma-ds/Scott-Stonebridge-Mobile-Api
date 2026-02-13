<?php

return [
    'api_key' => env('SHOPIFY_API_KEY'),
    'api_secret' => env('SHOPIFY_API_SECRET'),
    'scopes' => env('SHOPIFY_SCOPES'),
    'store_domain' => env('SHOPIFY_STORE_DOMAIN'),
    'access_token' => env('SHOPIFY_ACCESS_TOKEN'),
    'api_version' => env('SHOPIFY_API_VERSION', '2024-07'),
    'storefront_access_token' => env('SHOPIFY_STOREFRONT_ACCESS_TOKEN'),
    'cdn_base_url' => env('SHOPIFY_CDN_BASE_URL'),
    'store_url' => env('SHOPIFY_STORE_URL'),
    'currency' => env('CURRENCY', 'GBP'),


    'graphql' => [
        // enable hash verification in production only when you have precomputed checksums
        'verify_hashes' => env('GRAPHQL_VERIFY_HASHES', false),

        // where to store precomputed checksums (optional)
        'checksum_store' => storage_path('app/graphql_checksums.json'),

        // cache minutes for loaded queries
        'cache_minutes' => env('GRAPHQL_CACHE_MINUTES', 1440),

        // performance logging
        'performance_logging' => env('GRAPHQL_PERFORMANCE_LOGGING', true),
        
        // performance threshold in milliseconds (only log if duration exceeds this)
        'performance_threshold_ms' => env('GRAPHQL_PERFORMANCE_THRESHOLD_MS', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Configure cache TTL (time-to-live) values for different resource types.
    | Values are in seconds.
    |
    */
    'cache' => [
        'ttl' => [
            'product' => env('SHOPIFY_CACHE_TTL_PRODUCT', 900),      // 15 minutes
            'collection' => env('SHOPIFY_CACHE_TTL_COLLECTION', 1800), // 30 minutes
            'currency' => env('SHOPIFY_CACHE_TTL_CURRENCY', 86400),   // 24 hours
            'cart' => env('SHOPIFY_CACHE_TTL_CART', 3600),            // 1 hour
        ],
        'enabled' => env('SHOPIFY_CACHE_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Configuration
    |--------------------------------------------------------------------------
    |
    | Configure HTTP client settings for Shopify API requests.
    |
    */
    'http' => [
        'timeout' => env('SHOPIFY_HTTP_TIMEOUT', 30),           // seconds
        'connect_timeout' => env('SHOPIFY_HTTP_CONNECT_TIMEOUT', 10), // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Configure retry behavior for failed Shopify API requests.
    |
    */
    'retry' => [
        'enabled' => env('SHOPIFY_RETRY_ENABLED', true),
        'max_attempts' => env('SHOPIFY_RETRY_MAX_ATTEMPTS', 3),
        'initial_delay_ms' => env('SHOPIFY_RETRY_INITIAL_DELAY_MS', 100),
        'max_delay_ms' => env('SHOPIFY_RETRY_MAX_DELAY_MS', 5000),
        'multiplier' => env('SHOPIFY_RETRY_MULTIPLIER', 2.0),
        'jitter' => env('SHOPIFY_RETRY_JITTER', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker Configuration
    |--------------------------------------------------------------------------
    |
    | Configure circuit breaker pattern for Shopify API requests.
    |
    */
    'circuit_breaker' => [
        'enabled' => env('SHOPIFY_CIRCUIT_BREAKER_ENABLED', true),
        'failure_threshold' => env('SHOPIFY_CIRCUIT_BREAKER_FAILURE_THRESHOLD', 5),
        'success_threshold' => env('SHOPIFY_CIRCUIT_BREAKER_SUCCESS_THRESHOLD', 2),
        'timeout_seconds' => env('SHOPIFY_CIRCUIT_BREAKER_TIMEOUT_SECONDS', 60),
        'window_seconds' => env('SHOPIFY_CIRCUIT_BREAKER_WINDOW_SECONDS', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Configuration
    |--------------------------------------------------------------------------
    |
    | Configure rate limiting for API endpoints.
    |
    */
    'rate_limit' => [
        'enabled' => env('SHOPIFY_RATE_LIMIT_ENABLED', true),
        'max_attempts' => env('SHOPIFY_RATE_LIMIT_MAX_ATTEMPTS', 60),
        'decay_minutes' => env('SHOPIFY_RATE_LIMIT_DECAY_MINUTES', 1),
    ],
];
