<?php

return [

    'paths' => [
        'api/*',
        'google/auth-url',
        'google/callback',
        'shopify/get-time-slots',
        'shopify/receive-form',
        // and any other route you call from frontend directly across origins
    ],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    'allowed_origins' => ['https://scottstonebridge.com'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'Authorization', 'ngrok-skip-browser-warning', '*'],
    'exposed_headers' => [],
    'max_age' => 3600,
    'supports_credentials' => false,  // unless you need cookies
];


