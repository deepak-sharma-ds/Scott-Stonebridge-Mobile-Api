<?php

return [
    'api_key' => env('SHOPIFY_API_KEY'),
    'api_secret' => env('SHOPIFY_API_SECRET'),
    'scopes' => env('SHOPIFY_SCOPES'),
    'store_domain' => env('SHOPIFY_STORE_DOMAIN'),
    'access_token' => env('SHOPIFY_ACCESS_TOKEN'),
    'api_version' => env('SHOPIFY_API_VERSION', '2023-10'),
    'storefront_access_token' => env('SHOPIFY_STOREFRONT_ACCESS_TOKEN'),
    'cdn_base_url' => env('SHOPIFY_CDN_BASE_URL'),
    'store_url' => env('SHOPIFY_STORE_URL'),
];