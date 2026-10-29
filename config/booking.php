<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Booking Product Targeting Hierarchy
    |--------------------------------------------------------------------------
    | When orders arrive via Shopify webhooks, the BookingLineItemResolver
    | inspects line items using a 3-tier sequential matching hierarchy:
    |   1. Primary: Match by shopify_product_id against `product_ids`
    |   2. Fallback 1: Match by product handle against `product_handles`
    |   3. Fallback 2: Match by line item SKU against `product_skus`
    |
    | Values can be comma-separated strings in .env or configured arrays.
    */
    'product_ids' => array_filter(array_map(
        fn ($id) => (int) trim($id),
        explode(',', env('SHOPIFY_BOOKING_PRODUCT_IDS', ''))
    )),

    'product_handles' => array_filter(array_map(
        fn ($handle) => trim($handle),
        explode(',', env('SHOPIFY_BOOKING_PRODUCT_HANDLES', ''))
    )),

    'product_skus' => array_filter(array_map(
        fn ($sku) => trim($sku),
        explode(',', env('SHOPIFY_BOOKING_PRODUCT_SKUS', ''))
    )),

    /*
    |--------------------------------------------------------------------------
    | Booking Timezone
    |--------------------------------------------------------------------------
    | Base application timezone for all booking slots and Google Calendar sync.
    */
    'timezone' => env('APP_TIMEZONE', 'Europe/London'),

    /*
    |--------------------------------------------------------------------------
    | Google Sub-Calendar Configuration
    |--------------------------------------------------------------------------
    | Name of the secondary Google Calendar showing unbooked slots to Scott.
    */
    'sub_calendar_name' => env('GOOGLE_SLOTS_CALENDAR_NAME', 'Stonebridge - Available Slots'),
    'google_webhook_url' => env('GOOGLE_CALENDAR_WEBHOOK_URL'),
    'google_channel_token' => env('GOOGLE_CALENDAR_CHANNEL_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Shopify Webhook HMAC Verification
    |--------------------------------------------------------------------------
    | Validates X-Shopify-Hmac-Sha256 header against the configured webhook secret.
    */
    'hmac_enabled' => (bool) env('SHOPIFY_BOOKING_HMAC_ENABLED', false),
    'shopify_webhook_secret' => env('SHOPIFY_BOOKING_WEBHOOK_SECRET', env('SHOPIFY_WEBHOOK_SECRET', env('SHOPIFY_API_SECRET'))),

];
