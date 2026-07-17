<?php

return [

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
