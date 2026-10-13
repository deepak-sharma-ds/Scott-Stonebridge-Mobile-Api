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
    'hmac_enabled' => (bool) env('SHOPIFY_WEBHOOK_HMAC_ENABLED', false),
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
        .'Write a warm, compassionate, well-structured email reading. '
        .'Do not include a greeting/salutation (e.g. "Dear ...") or a sign-off (e.g. "Warm blessings, ...") — '
        .'output only the body of the reading itself, since the surrounding email template already supplies those.'
    ),

    /*
    |--------------------------------------------------------------------------
    | Shared formatting contract
    |--------------------------------------------------------------------------
    | The formatting contract instructs the model to write the reading as a
    | warm personal letter in flowing paragraphs, with soft ### subheadings and
    | a closing section. Blockquotes, tables, bullet lists and horizontal rules
    | are forbidden so the email never looks machine-generated.
    | A product row in email_reading_products may override `formatting_contract`.
    */
    'formatting_contract' => env(
        'READINGS_FORMATTING_CONTRACT',
        "Write the reading as a warm, personal letter in Markdown:\n"
        ."- Write in natural flowing paragraphs, as if typing a heartfelt email to one person.\n"
        ."- Use soft ### subheadings only to introduce each question, card, or main section. Keep them short and gentle.\n"
        ."- Do NOT use blockquotes (lines starting with '>'), tables, bullet points, numbered lists, horizontal dividers ('---'), or decorative symbols. Weave every point into the paragraphs instead.\n"
        ."- End with a dedicated closing section headed '### From My Heart to Yours' with warm, encouraging parting words.\n"
        ."- Do NOT include a greeting or salutation (e.g. 'Dear ...') — the email template already supplies it.\n"
        ."- Do NOT include a sign-off or closing signature (e.g. 'Warm blessings, Scott') — the email template already supplies it.\n"
        .'- Return ONLY the markdown reading body. Do not include raw HTML.'
    ),

    /*
    |--------------------------------------------------------------------------
    | Voice & tone rules
    |--------------------------------------------------------------------------
    | Rules governing tone, British spelling, varied sentence openings, and
    | banned clichés, included in every reading generation.
    */
    'voice_rules' => env('READINGS_VOICE_RULES')
        ? array_values(array_filter(array_map('trim', explode('|', (string) env('READINGS_VOICE_RULES')))))
        : [
            'Use British English spelling throughout (e.g. colour, realise, centre, honour).',
            'Vary sentence openings — avoid starting consecutive sentences or paragraphs with repetitive phrasing (e.g. "I feel", "I see", "There is").',
            'Avoid generic AI clichés and psychic tropes (e.g. "beacon of light", "tapestry of life", "delve into", "testament to", "as an AI").',
            'Keep the tone deeply personal, warm, grounded, and compassionate.',
        ],

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
    | Shopify fulfillment on send
    |--------------------------------------------------------------------------
    | After a reading email is sent, the matching Shopify order LINE ITEM is
    | fulfilled via the Admin GraphQL API (AdminApiClient). Only the reading
    | line item is fulfilled, so an order containing physical products is left
    | PARTIALLY_FULFILLED by Shopify automatically. Runs in its own job after
    | the send is marked complete, so a fulfillment failure never blocks or
    | re-sends the email. A per-delivery `fulfilled_at` flag keeps it idempotent.
    |
    | Set `enabled` false to leave Shopify orders untouched.
    | `notify_customer` controls whether Shopify sends its own shipping email.
    */
    'fulfillment' => [
        'enabled' => (bool) env('READINGS_FULFILL_ENABLED', true),
        'notify_customer' => (bool) env('READINGS_FULFILL_NOTIFY_CUSTOMER', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cancel on refund / cancellation
    |--------------------------------------------------------------------------
    | When a reading order is cancelled (orders/cancelled) or a reading line
    | item is refunded (refunds/create), the matching UNSENT deliveries are
    | marked `cancelled` so their queued send/generation jobs no-op — no email
    | goes out. Already-sent readings are left untouched (cannot be unsent).
    |
    | Set `enabled` false to make the cancel webhook inert.
    */
    'cancel' => [
        'enabled' => (bool) env('READINGS_CANCEL_ENABLED', true),
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
        'batch_size' => (int) env('READINGS_DISPATCH_BATCH', 100),
        'stuck_after_minutes' => (int) env('READINGS_STUCK_AFTER_MINUTES', 15),
        'max_releases' => (int) env('READINGS_MAX_RELEASES', 1),
    ],

];
