<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master kill switch
    |--------------------------------------------------------------------------
    | Every entry point of the marketing-push pipeline (Klaviyo flow webhook,
    | campaign sweep command, send jobs) checks this flag, so the whole
    | feature can be disabled from the environment without a deploy.
    */
    'enabled' => (bool) env('PUSH_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Klaviyo
    |--------------------------------------------------------------------------
    | api_key         — private key (pk_...) with campaigns:read + events:read.
    | webhook_secret  — shared secret the marketer sets as the
    |                   X-Klaviyo-Webhook-Secret header on every flow webhook
    |                   action (Klaviyo has no HMAC for flow webhooks).
    | webhook_auth_enabled — leave true; false only for local testing.
    | received_email_metric_id — Klaviyo's "Received Email" metric id, looked
    |                   up once via KlaviyoApiClient::getMetricId() and pinned
    |                   here so the sweep never has to resolve it per run.
    */
    'klaviyo' => [
        'api_key' => env('KLAVIYO_API_KEY'),
        'revision' => env('KLAVIYO_API_REVISION', '2025-04-15'),
        'webhook_secret' => env('KLAVIYO_WEBHOOK_SECRET'),
        'webhook_auth_enabled' => (bool) env('KLAVIYO_WEBHOOK_AUTH_ENABLED', true),
        'received_email_metric_id' => env('KLAVIYO_RECEIVED_EMAIL_METRIC_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging
    |--------------------------------------------------------------------------
    | credentials — absolute path to the Firebase Admin SDK service-account
    | JSON. Keep it outside the webroot (storage/app/firebase/) and out of
    | git; production receives it as a deployment secret.
    */
    'firebase' => [
        'credentials' => env('FIREBASE_CREDENTIALS', storage_path('app/firebase/google-services.json')),
        'project_id' => env('FIREBASE_PROJECT_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    | Dedicated queues so a large campaign fan-out never blocks readings,
    | chatbot, or other domain queues. On very large lists the database
    | driver will hold one job row per recipient — if campaign volume grows,
    | point PUSH_QUEUE_CONNECTION at a Redis connection; no code change needed.
    */
    'queue' => [
        'connection' => env('PUSH_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
        'default' => env('PUSH_QUEUE', 'push'),
        'fanout' => env('PUSH_FANOUT_QUEUE', 'push-fanout'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Campaign sweep
    |--------------------------------------------------------------------------
    | The sweep discovers newly SENT Klaviyo campaigns and fans out pushes to
    | their recipients via the Events API ("Received Email" metric).
    |
    | lookback_hours — how far back the campaign listing looks. Campaigns
    |   whose send_time predates the feature (or the lookback window) are
    |   seeded as completed so they never push retroactively.
    | settle_minutes — wait after send_time before sweeping; Klaviyo events
    |   are written asynchronously and can lag a few minutes.
    */
    'sweep' => [
        'enabled' => (bool) env('PUSH_SWEEP_ENABLED', false),
        'lookback_hours' => (int) env('PUSH_SWEEP_LOOKBACK_HOURS', 24),
        'settle_minutes' => (int) env('PUSH_SWEEP_SETTLE_MINUTES', 15),
        'page_size' => (int) env('PUSH_SWEEP_PAGE_SIZE', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification defaults
    |--------------------------------------------------------------------------
    | Used when a flow webhook omits title/body, or for campaign pushes where
    | no per-campaign copy is available. deep_link is what the mobile app
    | falls back to when a notification carries no explicit destination.
    */
    'defaults' => [
        'title' => env('PUSH_DEFAULT_TITLE', 'New from Scott Stonebridge'),
        'body' => env('PUSH_DEFAULT_BODY', 'We just sent you something special — take a look.'),
        'deep_link' => env('PUSH_DEFAULT_DEEP_LINK', 'app://home'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Skipped-recipient logging
    |--------------------------------------------------------------------------
    | Most campaign recipients have no app device; by default they are
    | skipped silently (debug log only) to keep push_notifications lean.
    | Enable to write one `skipped` row per device-less recipient.
    */
    'log_skipped' => (bool) env('PUSH_LOG_SKIPPED', false),

    /*
    |--------------------------------------------------------------------------
    | Testing allowlist (TEMPORARY)
    |--------------------------------------------------------------------------
    | When non-empty, pushes are only processed for these recipient emails.
    | All other recipients are logged and skipped. Clear PUSH_TEST_EMAILS to
    | enable pushes for everyone.
    */
    'test_emails' => array_values(array_filter(array_map(
        fn ($e) => strtolower(trim((string) $e)),
        explode(',', (string) env('PUSH_TEST_EMAILS', ''))
    ))),

];
