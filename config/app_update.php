<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mobile App Versions & Update Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for compulsory and optional mobile app updates.
    | iOS and Android can be configured independently with their respective
    | minimum required version, current/latest version, store URL, and
    | optional update message.
    |
    */

    'platforms' => [
        'ios' => [
            'minimum_version' => env('APP_UPDATE_IOS_MIN_VERSION', '1.0.0'),
            'latest_version' => env('APP_UPDATE_IOS_LATEST_VERSION', '1.0.0'),
            'store_url' => env('APP_UPDATE_IOS_STORE_URL', 'https://apps.apple.com/app/scott-stonebridge/id123456789'),
            'message' => env('APP_UPDATE_IOS_MESSAGE', null),
            'force_update_message' => env('APP_UPDATE_IOS_FORCE_MESSAGE', null),
        ],

        'android' => [
            'minimum_version' => env('APP_UPDATE_ANDROID_MIN_VERSION', '1.0.0'),
            'latest_version' => env('APP_UPDATE_ANDROID_LATEST_VERSION', '1.0.0'),
            'store_url' => env('APP_UPDATE_ANDROID_STORE_URL', 'https://play.google.com/store/apps/details?id=com.scottstonebridge.app'),
            'message' => env('APP_UPDATE_ANDROID_MESSAGE', null),
            'force_update_message' => env('APP_UPDATE_ANDROID_FORCE_MESSAGE', null),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Update Messages
    |--------------------------------------------------------------------------
    |
    | Default messages shown to users when an update is available or required,
    | used when platform-specific messages are not defined.
    |
    */
    'default_message' => env('APP_UPDATE_MESSAGE', 'A new version of the app is available. Please update to continue.'),
    'force_update_message' => env('APP_UPDATE_FORCE_MESSAGE', 'This version is no longer supported. Please update to the latest version to continue using the app.'),

];
