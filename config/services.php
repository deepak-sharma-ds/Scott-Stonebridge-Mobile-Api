<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'shopify' => [
        'shop_domain' => env('SHOPIFY_STORE_DOMAIN'),
        'admin_token' => env('SHOPIFY_ACCESS_TOKEN'),
        'api_version' => env('SHOPIFY_API_VERSION', '2025-10'),
        'webhook_secret' => env('SHOPIFY_WEBHOOK_SECRET'),
    ],

    'klaviyo' => [
        'api_key' => env('KLAVIYO_API_KEY'),
        'webhook_secret' => env('KLAVIYO_WEBHOOK_SECRET'), // shared secret you invent yourself
        'revision' => env('KLAVIYO_API_REVISION', '2025-04-15'),
    ],
];
