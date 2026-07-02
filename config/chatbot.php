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
        'output_budget' => (int) env('CHATBOT_TOKEN_BUDGET_OUTPUT', 1100),
        'history_tail' => (int) env('CHATBOT_HISTORY_TAIL', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Generation sampling
    |--------------------------------------------------------------------------
    |
    | Temperature for the chat / tool-loop completions. The intent classifier
    | stays deterministic (temperature 0) and is intentionally NOT driven by
    | this key — see IntentDetectionService.
    |
    */
    'generation' => [
        'temperature' => (float) env('CHATBOT_TEMPERATURE', 0.6),
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

    /*
    |--------------------------------------------------------------------------
    | Shopify MCP (Storefront + Customer Account) endpoints
    |--------------------------------------------------------------------------
    |
    | `{shop}` is substituted with the active shop domain at call time. The
    | UCP endpoint (Unified Catalog Protocol) hosts catalog discovery tools
    | (search_catalog, get_product, lookup_catalog); the storefront endpoint
    | hosts cart, policy + checkout tools. The customer discovery URL is the
    | well-known OpenID configuration document for the Customer Account API.
    */
    'mcp' => [
        'storefront_endpoint' => env('SHOPIFY_MCP_STOREFRONT', 'https://{shop}/api/mcp'),
        // Catalog discovery tools (search_catalog, get_product, lookup_catalog)
        // live on the SAME `/api/mcp` endpoint as the rest of the Storefront
        // MCP tools. Kept as a separate config key so future Shopify topology
        // changes (e.g. a dedicated UCP host) can be swapped via env only.
        'ucp_endpoint' => env('SHOPIFY_MCP_UCP', 'https://{shop}/api/mcp'),
        'customer_discovery' => '/.well-known/customer-account-api',
        'timeout_ms' => (int) env('SHOPIFY_MCP_TIMEOUT_MS', 15000),
        'retry_on_status' => [502, 503, 504],
        'cache_ttl_seconds' => [
            'search_catalog' => 120,
            'get_product' => 300,
            'lookup_catalog' => 300,
            'search_shop_policies_and_faqs' => 900,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Shopify Customer Account OAuth (PKCE) for the in-chat sign-in flow
    |--------------------------------------------------------------------------
    */
    'oauth' => [
        'client_id' => env('SHOPIFY_CUSTOMER_CLIENT_ID'),
        // Confidential clients (token_endpoint_auth_methods = client_secret_basic)
        // require the secret on the token exchange. Leave blank for a public
        // PKCE-only client.
        'client_secret' => env('SHOPIFY_CUSTOMER_CLIENT_SECRET'),
        'redirect_uri' => env('SHOPIFY_CUSTOMER_REDIRECT_URI', env('APP_URL').'/api/v1/ai/oauth/customer/callback'),
        // `openid` is required by the auth server. `customer-account-api:full`
        // grants the issued token access to the Customer Account API (orders,
        // profile, addresses). Shopify Customer MCP uses the SAME token + same
        // scope — there is no separate `customer-account-mcp-api:full` scope
        // on a stock Headless app (Shopify rejects it at /authorize with
        // "scope invalid, unknown, or malformed"). If the MCP endpoint
        // returns 401 after sign-in, the cause is the access_token type /
        // Headless app MCP enrollment, NOT a missing scope — investigate the
        // token shape rather than re-adding a non-existent scope here.
        'scopes' => array_values(array_filter(array_map(
            'trim',
            preg_split(
                '/[\s,]+/',
                (string) env(
                    'SHOPIFY_CUSTOMER_SCOPES',
                    'openid email customer-account-api:full',
                ),
            ) ?: [],
        ))),
        // PKCE state TTL — must outlast the full OAuth round-trip including
        // OTP email delivery + user-read time. 600s (10min) was too tight in
        // practice; 1800s (30min) covers slow email delivery without giving a
        // stolen state value a meaningfully larger replay window.
        'pkce_session_ttl' => (int) env('SHOPIFY_OAUTH_PKCE_TTL', 1800),
        'token_ttl_seconds' => (int) env('SHOPIFY_OAUTH_TOKEN_TTL', 3600),
    ],

];
