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

];
