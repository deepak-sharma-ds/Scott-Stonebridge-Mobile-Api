<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | AI Chatbot Configuration
    |--------------------------------------------------------------------------
    |
    | All tunables for the AI orchestration layer. Reads from .env so values
    | can be tuned per environment without touching code.
    |
    */

    'enabled' => env('CHATBOT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | OpenAI model selection
    |--------------------------------------------------------------------------
    */
    'models' => [
        'default' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
        'complex' => env('OPENAI_COMPLEX_MODEL', 'gpt-4.1'),
        'classifier' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Token budgets (input + output) per call
    |--------------------------------------------------------------------------
    */
    'tokens' => [
        'input_budget' => (int) env('CHATBOT_TOKEN_BUDGET_INPUT', 3500),
        'output_budget' => (int) env('CHATBOT_TOKEN_BUDGET_OUTPUT', 600),
        'history_tail' => (int) env('CHATBOT_HISTORY_TAIL', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Message + safety constraints
    |--------------------------------------------------------------------------
    */
    'message' => [
        'max_length' => (int) env('CHATBOT_MESSAGE_MAX_LENGTH', 2000),
        'min_length' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits (in addition to the named RateLimiter buckets)
    |--------------------------------------------------------------------------
    */
    'rate_limits' => [
        'per_session_per_minute' => (int) env('CHATBOT_RATE_LIMIT_PER_SESSION', 20),
        'per_ip_per_minute' => (int) env('CHATBOT_RATE_LIMIT_PER_IP', 60),
        'per_ip_per_day' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Intent detection
    |--------------------------------------------------------------------------
    */
    'intent' => [
        'confidence_threshold' => (float) env('CHATBOT_INTENT_CONFIDENCE_THRESHOLD', 0.65),
        'cache_ttl' => 300,
        'supported' => [
            'product_support',
            'recommendation',
            'order_tracking',
            'refund_policy',
            'shipping_question',
            'cart_help',
            'greeting',
            'upsell_opportunity',
            'cross_sell_opportunity',
            'unknown',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Shopify context resolution
    |--------------------------------------------------------------------------
    */
    'context' => [
        'cache_ttl' => (int) env('CHATBOT_CONTEXT_CACHE_TTL', 180),
        'cache_prefix' => 'ai:ctx',
    ],

    /*
    |--------------------------------------------------------------------------
    | Product recommendations
    |--------------------------------------------------------------------------
    */
    'recommendation' => [
        'limit' => (int) env('CHATBOT_RECOMMENDATION_LIMIT', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Safety / jailbreak detection
    |--------------------------------------------------------------------------
    |
    | Regex patterns checked against the sanitized user message. A match
    | results in an AISafetyViolationException without contacting OpenAI.
    |
    */
    'safety' => [
        'banned_patterns' => [
            '/ignore (all )?previous instructions/i',
            '/you are (now )?(a )?developer mode/i',
            '/system prompt[: ]/i',
            '/<script\b/i',
            '/\bDROP\s+TABLE\b/i',
            '/\bUNION\s+SELECT\b/i',
        ],
        'strip_html' => true,
        'normalize_unicode' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Escalation
    |--------------------------------------------------------------------------
    */
    'escalation' => [
        'email' => env('CHATBOT_ESCALATION_EMAIL'),
        'slack_webhook' => env('CHATBOT_ESCALATION_SLACK_WEBHOOK'),
        'auto_triggers' => [
            'refund_failure_keywords' => [
                'refund', 'chargeback', 'fraud', 'lawyer', 'lawsuit',
            ],
            'sentiment_keywords' => [
                'angry', 'furious', 'unacceptable', 'terrible', 'worst',
            ],
            'max_failed_responses' => 3,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue connection + queue name for AI jobs
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'connection' => env('CHATBOT_QUEUE_CONNECTION', 'redis'),
        'name' => env('CHATBOT_QUEUE_NAME', 'ai'),
    ],

    /*
    |--------------------------------------------------------------------------
    | System prompt template (Blade view name, no .blade.php)
    |--------------------------------------------------------------------------
    */
    'prompts' => [
        'system_template' => 'ai.prompts.system',
    ],

];
