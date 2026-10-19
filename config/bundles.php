<?php

/*
|--------------------------------------------------------------------------
| Predefined Product Bundles (Scott Bundle App)
|--------------------------------------------------------------------------
|
| Credentials for the "Scott Bundles" Shopify app (Dev Dashboard app).
| Token resolution order (see ShopifyBundleCredentials):
|   1. BUNDLE_STORE_ID          -> use a record from the `stores` table
|   2. BUNDLE_ADMIN_ACCESS_TOKEN -> static Admin API token
|   3. BUNDLE_APP_CLIENT_ID + BUNDLE_APP_CLIENT_SECRET -> client credentials
|      grant (token is fetched and cached for ~24h)
|
*/

return [

    'store_id' => env('BUNDLE_STORE_ID'),

    'shop_domain' => env('BUNDLE_SHOP_DOMAIN'),

    'client_id' => env('BUNDLE_APP_CLIENT_ID'),

    'client_secret' => env('BUNDLE_APP_CLIENT_SECRET'),

    'access_token' => env('BUNDLE_ADMIN_ACCESS_TOKEN'),

    'api_version' => env('BUNDLE_SHOPIFY_API_VERSION', '2026-07'),

    // Tag + product type applied to every bundle parent product created by the app.
    'product_tag' => 'scott-bundle',
    'product_type' => 'Bundle',

    // Product metafield read by the theme app extension block.
    'metafield_namespace' => 'scott_bundle',
    'metafield_key' => 'config',

    // Online Store publication. Leave empty to auto-detect by name.
    'publication_id' => env('BUNDLE_PUBLICATION_ID'),
    'publication_name' => env('BUNDLE_PUBLICATION_NAME', 'Online Store'),

    'map' => [
        // Block saving/activating a bundle priced below the summed component MAP.
        'enforce' => (bool) env('BUNDLE_MAP_ENFORCE', true),
        // Block when a component SKU has no MAP price on file.
        'require_all_skus' => (bool) env('BUNDLE_MAP_REQUIRE_ALL', false),
    ],

    /*
    | How bundle funnel discounts are applied at checkout:
    |  - function: the app's Shopify Discount Function (extensions/bundle-discount). The discount applies only
    |    when every product of the bundle is in the cart, added as the bundle. Custom apps can run Functions on
    |    Shopify Plus (and development stores).
    |  - native:   Shopify basic automatic discounts, one per discount value. Works on every plan, but applies
    |    whenever enough of the discounted products are in the cart, even without the rest of the bundle.
    */
    'discount_mode' => env('BUNDLE_DISCOUNT_MODE', 'function'),
    'discount_function_handle' => env('BUNDLE_DISCOUNT_FUNCTION', 'bundle-discount'),

    'limits' => [
        'max_components' => 30,
        'max_quantity' => 99,
    ],

];
