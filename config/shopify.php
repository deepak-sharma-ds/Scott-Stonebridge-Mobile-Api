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


    'graphql' => [
        // enable hash verification in production only when you have precomputed checksums
        'verify_hashes' => env('GRAPHQL_VERIFY_HASHES', false),

        // where to store precomputed checksums (optional)
        'checksum_store' => storage_path('app/graphql_checksums.json'),

        // cache minutes for loaded queries
        'cache_minutes' => env('GRAPHQL_CACHE_MINUTES', 1440),
    ]
];
