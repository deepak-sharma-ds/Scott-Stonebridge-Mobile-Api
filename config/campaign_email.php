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
        .'Write a warm, compelling marketing email promoting the given product. '
        .'Use clear paragraphs, no headings, no markdown. '
        .'Do not include a greeting/salutation (e.g. "Dear ...") or a sign-off (e.g. "Warm blessings, ...") — '
        .'output only the body of the message itself, since the surrounding email template already supplies those.'
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

    /*
    |--------------------------------------------------------------------------
    | Cancel on refund / cancellation
    |--------------------------------------------------------------------------
    | Same algorithm as email_reading.cancel, tuned independently for the
    | campaign flow. Set `enabled` false to make the cancel webhook inert.
    */
    'cancel' => [
        'enabled' => (bool) env('CAMPAIGN_EMAIL_CANCEL_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | VIP tag
    |--------------------------------------------------------------------------
    | The Shopify product tag that marks a product as a Campaign (vip)
    | product. Read during the unlisted sync and stored as
    | `unlisted_products.is_vip`; order webhooks carry no product tags, so
    | routing between the Reading and Campaign flows uses that local flag.
    */
    'vip_tag' => env('CAMPAIGN_EMAIL_VIP_TAG', 'vip'),

    /*
    |--------------------------------------------------------------------------
    | Shopify Webhook HMAC (optional, disabled by default)
    |--------------------------------------------------------------------------
    | Independent of the reading flow's `email_reading.hmac_enabled`. When
    | false the VerifyShopifyWebhookHmac middleware lets traffic through.
    | The secret falls back to the shared SHOPIFY_WEBHOOK_SECRET.
    */
    'hmac_enabled' => (bool) env('CAMPAIGN_EMAIL_HMAC_ENABLED', false),
    'shopify_webhook_secret' => env('CAMPAIGN_EMAIL_SHOPIFY_WEBHOOK_SECRET', env('SHOPIFY_WEBHOOK_SECRET')),

    /*
    |--------------------------------------------------------------------------
    | Testing allowlist (optional)
    |--------------------------------------------------------------------------
    | Same behaviour as email_reading.test_emails: when non-empty only orders
    | whose customer email is listed are processed; every other order is
    | recorded as an `ignored` webhook event. Empty (default) = no filter.
    */
    'test_emails' => array_values(array_filter(array_map(
        fn ($e) => strtolower(trim((string) $e)),
        explode(',', (string) env('CAMPAIGN_EMAIL_TEST_EMAILS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Due-delivery dispatcher
    |--------------------------------------------------------------------------
    | The delivery row (status + scheduled_at) is the single source of truth
    | for when an email goes out — there are no long-delayed queue jobs. A
    | scheduler command runs every minute, atomically claims due deliveries
    | and dispatches an immediate send job for each claim.
    |
    | batch_size           max deliveries claimed per run.
    | stuck_after_minutes  a delivery left in `sending` longer than this
    |                      (worker crash, lost job) is recovered: finalized as
    |                      sent if the mail already went out, otherwise
    |                      released for re-dispatch. Keep it above the send
    |                      job's total retry/backoff time.
    | max_releases         how many times a stalled claim is auto-released
    |                      before the delivery is marked failed and an admin
    |                      is notified instead.
    */
    'dispatch' => [
        'batch_size' => (int) env('CAMPAIGN_EMAIL_DISPATCH_BATCH', 100),
        'stuck_after_minutes' => (int) env('CAMPAIGN_EMAIL_STUCK_AFTER_MINUTES', 15),
        'max_releases' => (int) env('CAMPAIGN_EMAIL_MAX_RELEASES', 1),
    ],

];
