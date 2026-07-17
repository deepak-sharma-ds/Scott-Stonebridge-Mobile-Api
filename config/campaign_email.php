<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenAI
    |--------------------------------------------------------------------------
    | Reuses the global OPENAI_MODEL env (currently gpt-4.1-mini). A row in
    | the campaign_products table may override `model`/`max_tokens` per
    | (campaign, product) pairing.
    */
    'openai_model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
    'max_tokens' => env('CAMPAIGN_EMAIL_MAX_TOKENS', 1500),
    'system_prompt' => env(
        'CAMPAIGN_EMAIL_SYSTEM_PROMPT',
        'You are Scott Stonebridge, an award-winning UK psychic medium. '
        .'Write a warm, compelling marketing email promoting the given product, addressed to the customer. '
        .'Use clear paragraphs, no headings, no markdown. Sign off as "Warm blessings, Scott Stonebridge".'
    ),

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    | Dedicated queue names, isolated from the reading flow's `readings` /
    | `readings-mail` queues.
    */
    'queue' => [
        'connection' => env('CAMPAIGN_EMAIL_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
        'process' => env('CAMPAIGN_EMAIL_QUEUE', 'campaign-emails'),
        'mail' => env('CAMPAIGN_EMAIL_MAIL_QUEUE', 'campaign-emails-mail'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mail
    |--------------------------------------------------------------------------
    | admin_notify_email — recipient for delivery-failure notifications.
    | Falls back through the reading flow's admin email before MAIL_FROM_ADDRESS.
    */
    'admin_notify_email' => env('CAMPAIGN_EMAIL_ADMIN_EMAIL')
        ?: env('READINGS_ADMIN_EMAIL')
            ?: env('ADMIN_EMAIL')
                ?: env('CONTACT_ADMIN_EMAIL')
                    ?: env('MAIL_FROM_ADDRESS'),

    /*
    |--------------------------------------------------------------------------
    | Scheduled send
    |--------------------------------------------------------------------------
    | Same algorithm as email_reading.schedule (shared via
    | App\Services\Shopify\ShippingScheduleResolver), tuned independently for
    | the campaign flow.
    */
    'schedule' => [
        'enabled' => (bool) env('CAMPAIGN_EMAIL_SCHEDULE_ENABLED', true),
        'min_days' => (int) env('CAMPAIGN_EMAIL_SCHEDULE_MIN_DAYS', 3),
        'max_days' => (int) env('CAMPAIGN_EMAIL_SCHEDULE_MAX_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Same-day expedite (orders/updated)
    |--------------------------------------------------------------------------
    | Same algorithm as email_reading.expedite, tuned independently for the
    | campaign flow.
    */
    'expedite' => [
        'enabled' => (bool) env('CAMPAIGN_EMAIL_EXPEDITE_ENABLED', true),
        'min_hours' => (int) env('CAMPAIGN_EMAIL_EXPEDITE_MIN_HOURS', 1),
        'max_hours' => (int) env('CAMPAIGN_EMAIL_EXPEDITE_MAX_HOURS', 24),
        'shipping_titles' => array_values(array_filter(array_map(
            fn ($t) => strtolower(trim((string) $t)),
            explode('|', (string) env(
                'CAMPAIGN_EMAIL_EXPEDITE_SHIPPING_TITLES',
                'SAME DAY GUARANTEE - Via Email'
            ))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Shopify fulfillment on send
    |--------------------------------------------------------------------------
    | After a campaign email is sent, the matching Shopify order LINE ITEM is
    | fulfilled via the Admin GraphQL API (AdminApiClient), reusing the same
    | OrderFulfillmentService the reading flow uses. Runs in its own job after
    | the send is marked complete, so a fulfillment failure never blocks or
    | re-sends the email. A per-delivery `fulfilled_at` flag keeps it idempotent.
    */
    'fulfillment' => [
        'enabled' => (bool) env('CAMPAIGN_EMAIL_FULFILL_ENABLED', true),
        'notify_customer' => (bool) env('CAMPAIGN_EMAIL_FULFILL_NOTIFY_CUSTOMER', false),
    ],

];
