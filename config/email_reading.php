<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shopify Webhook HMAC
    |--------------------------------------------------------------------------
    | When `hmac_enabled` is false the VerifyShopifyWebhookHmac middleware
    | lets traffic through (useful in dev). Flip to true in production once
    | the webhook is registered and SHOPIFY_WEBHOOK_SECRET is set.
    */
    'hmac_enabled' => env('SHOPIFY_WEBHOOK_HMAC_ENABLED', false),
    'shopify_webhook_secret' => env('SHOPIFY_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | OpenAI
    |--------------------------------------------------------------------------
    | Reuses the global OPENAI_MODEL env (currently gpt-4.1-mini). A row in
    | the email_reading_products table may override `model` per product.
    */
    'openai_model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
    'max_tokens' => env('READINGS_MAX_TOKENS', 1500),
    'system_prompt' => env(
        'READINGS_SYSTEM_PROMPT',
        'You are Scott Stonebridge, an award-winning UK psychic medium. '
        .'Write a warm, compassionate, well-structured email reading addressed to the customer. '
        .'Use clear paragraphs, no headings, no markdown. Sign off as "Warm blessings, Scott Stonebridge".'
    ),

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    | Dedicated queue names so a stuck reading job never blocks unrelated
    | work (e.g. the chatbot `ai` queue or the meditation entitlement flow).
    */
    'queue' => [
        'connection' => env('READINGS_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
        'process' => env('READINGS_QUEUE', 'readings'),
        'mail' => env('READINGS_MAIL_QUEUE', 'readings-mail'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mail
    |--------------------------------------------------------------------------
    | admin_notify_email — recipient for delivery-failure notifications.
    | Falls back through ADMIN_EMAIL / CONTACT_ADMIN_EMAIL to MAIL_FROM_ADDRESS.
    */
    'admin_notify_email' => env('READINGS_ADMIN_EMAIL')
        ?: env('ADMIN_EMAIL')
            ?: env('CONTACT_ADMIN_EMAIL')
                ?: env('MAIL_FROM_ADDRESS'),

    'default_view' => env('READINGS_DEFAULT_VIEW', 'mail.email-reading'),

    /*
    |--------------------------------------------------------------------------
    | Scheduled send
    |--------------------------------------------------------------------------
    | The reading is GENERATED immediately on order, but the customer email is
    | held and sent at a random time between `min_days` and `max_days` after
    | ordering. One timestamp is computed per order (shared across that order's
    | reading line items) and persisted on the delivery row, so retries and
    | duplicate webhooks never re-randomize the send time.
    |
    | Set `enabled` false (or min/max both 0) to send immediately — exactly the
    | pre-scheduling behavior.
    */
    'schedule' => [
        'enabled' => (bool) env('READINGS_SCHEDULE_ENABLED', true),
        'min_days' => (int) env('READINGS_SCHEDULE_MIN_DAYS', 3),
        'max_days' => (int) env('READINGS_SCHEDULE_MAX_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Same-day expedite (orders/updated)
    |--------------------------------------------------------------------------
    | When a customer buys the "same day" upgrade on an existing order, Shopify
    | fires an orders/updated webhook carrying the upgrade as a SHIPPING LINE
    | (not a product). On detecting a matching shipping line, the order's
    | reading send is pulled forward from the 3-7 day window to a random time
    | within `min_hours`-`max_hours` from now (computed once per order, shared
    | across that order's deliveries).
    |
    | `shipping_titles` is matched case-insensitively against each line's title
    | AND code; removed lines (is_removed=true) are ignored. Pipe-delimited so a
    | title containing a comma is safe.
    |
    | Set `enabled` false to make the orders/updated webhook inert.
    */
    'expedite' => [
        'enabled' => (bool) env('READINGS_EXPEDITE_ENABLED', true),
        'min_hours' => (int) env('READINGS_EXPEDITE_MIN_HOURS', 1),
        'max_hours' => (int) env('READINGS_EXPEDITE_MAX_HOURS', 24),
        'shipping_titles' => array_values(array_filter(array_map(
            fn ($t) => strtolower(trim((string) $t)),
            explode('|', (string) env(
                'READINGS_EXPEDITE_SHIPPING_TITLES',
                'SAME DAY GUARANTEE - Via Email'
            ))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Testing allowlist (TEMPORARY)
    |--------------------------------------------------------------------------
    | When non-empty, the reading webhook will only process orders whose
    | customer email is in this list. All other orders are logged and
    | skipped. Clear READINGS_TEST_EMAILS (or set empty) to disable the
    | filter and resume processing every order.
    */
    'test_emails' => array_values(array_filter(array_map(
        fn ($e) => strtolower(trim((string) $e)),
        explode(',', (string) env('READINGS_TEST_EMAILS', ''))
    ))),

];
