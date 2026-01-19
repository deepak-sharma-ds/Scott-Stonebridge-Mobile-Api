<?php

return [
    'APP_NAME' => env('APP_NAME', 'Scottstonebridge'),
    'APP_TIMEZONE' => env('APP_TIMEZONE', 'Europe/London'),
    'APP_URL' => env('APP_URL'),
    'LOG_LEVEL' => env('LOG_LEVEL', 'debug'),

    'ADMIN_USER_ID' => env('ADMIN_USER_ID'),
    'ADMIN_EMAIL' => env('ADMIN_EMAIL'),

    'SHOPIFY_API_KEY' => env('SHOPIFY_API_KEY'),
    'SHOPIFY_API_SECRET' => env('SHOPIFY_API_SECRET'),
    'SHOPIFY_SCOPES' => env('SHOPIFY_SCOPES', 'read_products,write_products,read_orders,write_orders,read_customers,write_customers,read_script_tags,write_script_tags'),
    'SHOPIFY_STORE_DOMAIN' => env('SHOPIFY_STORE_DOMAIN'),
    'SHOPIFY_ACCESS_TOKEN' => env('SHOPIFY_ACCESS_TOKEN'),
    'SHOPIFY_API_VERSION' => env('SHOPIFY_API_VERSION', '2024-07'),
    'SHOPIFY_STOREFRONT_ACCESS_TOKEN' => env('SHOPIFY_STOREFRONT_ACCESS_TOKEN'),
    'SHOPIFY_CDN_BASE_URL' => env('SHOPIFY_CDN_BASE_URL'),
    'SHOPIFY_STORE_URL' => env('SHOPIFY_STORE_URL'),

    'GOOGLE_REDIRECT_URI' => env('GOOGLE_REDIRECT_URI', 'https://scottmobileapp.24livehost.com/api/google/callback'),
    'GOOGLE_ADMIN_REDIRECT_URI' => env('GOOGLE_ADMIN_REDIRECT_URI', 'https://scottmobileapp.24livehost.com/admin/google-calendar/auth'),

    'FFMPEG_PATH' => env('FFMPEG_PATH', 'ffmpeg')
];
