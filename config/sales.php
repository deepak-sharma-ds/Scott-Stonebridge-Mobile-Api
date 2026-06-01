<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | AI Sales Agent (Phase 2) Configuration
    |--------------------------------------------------------------------------
    |
    | Tunables for the proactive trigger engine, lead capture, upsell engine,
    | knowledge sync, and conversion analytics. Reads from .env so values can
    | be tuned per environment without touching code. Every threshold lives
    | here — never inline a literal in the services that consume them.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Queue connection + per-feature queue names
    |--------------------------------------------------------------------------
    |
    | All sales jobs dispatch via Redis (config('chatbot.queue.connection'))
    | onto these dedicated queues. Local dev runs:
    |   php artisan queue:work redis --queue=ai,sales,recovery,sync,analytics
    |
    */
    'queue' => [
        'connection' => env('CHATBOT_SALES_QUEUE_CONNECTION', env('CHATBOT_QUEUE_CONNECTION', 'redis')),
        'sales' => env('CHATBOT_SALES_QUEUE', 'sales'),
        'recovery' => env('CHATBOT_RECOVERY_QUEUE', 'recovery'),
        'sync' => env('CHATBOT_SYNC_QUEUE', 'sync'),
        'analytics' => env('CHATBOT_ANALYTICS_QUEUE', 'analytics'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Proactive Trigger Engine (Phase A)
    |--------------------------------------------------------------------------
    */
    'triggers' => [
        // Redis TTL (seconds) for the "already fired in this session" flag.
        'session_fired_ttl' => (int) env('SALES_TRIGGER_FIRED_TTL', 86400),

        // Allowed page_type values for trigger_rules.page_type column.
        'page_types' => ['home', 'product', 'cart', 'collection', 'all'],

        // Allowed trigger_type values for trigger_rules.trigger_type column.
        'trigger_types' => ['exit_intent', 'time_on_page', 'scroll_depth', 'cart_abandonment'],

        // Allowed values for the POST /triggers/event endpoint.
        'event_types' => ['trigger_opened', 'trigger_dismissed'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Lead Capture + Abandon Recovery (Phase B)
    |--------------------------------------------------------------------------
    */
    'leads' => [
        'sources' => ['proactive_trigger', 'manual_input', 'escalation'],
        'statuses' => ['new', 'recovery_sent', 'converted', 'unsubscribed'],

        // Delay before SendAbandonRecoveryEmailJob runs after session end.
        'recovery_delay_minutes' => (int) env('SALES_RECOVERY_DELAY_MINUTES', 30),

        // Per-session rate limit for POST /leads/capture (handled by
        // RateLimiter::for('ai-lead-capture') — value mirrored here for docs).
        'capture_rate_limit_per_min' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Upsell / Cross-Sell Intelligence (Phase C)
    |--------------------------------------------------------------------------
    */
    'upsell' => [
        // Fallback free-shipping threshold when shop_settings has no value.
        // Stored as a major-unit float (e.g. 50.00 = £50.00 / $50.00).
        'default_free_shipping_threshold' => (float) env('CHATBOT_DEFAULT_FREE_SHIP_THRESHOLD', 50.00),

        // Maximum upsell suggestions returned per request (post-dedupe).
        'max_results' => (int) env('SALES_UPSELL_MAX_RESULTS', 3),

        // Cache TTL (seconds) for productRecommendations per product ID.
        'cache_ttl' => (int) env('SALES_UPSELL_CACHE_TTL', 600),

        // Only mention free-shipping gap when within this fraction of threshold.
        'free_ship_gap_visibility' => (float) env('SALES_FREE_SHIP_GAP_VISIBILITY', 0.20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Store Knowledge Sync (Phase D)
    |--------------------------------------------------------------------------
    */
    'knowledge' => [
        'content_types' => ['page', 'policy', 'blog', 'faq', 'custom', 'product', 'url'],

        // Hour-of-day (0–23) for the daily sync job to run.
        'sync_hour' => (int) env('KNOWLEDGE_SYNC_HOUR', 2),

        // Redis cache TTL (seconds) for the per-shop knowledge index.
        'cache_ttl' => (int) env('SALES_KNOWLEDGE_CACHE_TTL', 86400),

        // Per-item summary cap (tokens, approx 4 chars/token).
        'item_summary_max_tokens' => (int) env('SALES_KNOWLEDGE_ITEM_TOKENS', 300),

        // Total injected knowledge cap when assembling the prompt block.
        // Bumped from 500 → 1200 so the top 5-8 ranked rows actually fit
        // after the relevance picker. Still well under the 800-token system
        // prompt guard once the rest of the system prompt is accounted for
        // because the picker only emits short summary lines.
        'prompt_block_max_tokens' => (int) env('SALES_KNOWLEDGE_PROMPT_TOKENS', 1200),

        // Pagination size for Admin API list queries.
        'admin_page_size' => (int) env('SALES_KNOWLEDGE_PAGE_SIZE', 50),

        // Intent → content_type mapping for getKnowledgeForPrompt().
        // Broadened: every intent now sees `faq` + `custom` rows too so
        // merchant-authored knowledge always has a chance to land in the
        // prompt. The `_default` key is a catch-all used by the prompt
        // builder when the detected intent is missing from the map
        // (e.g. INTENT_UNKNOWN, INTENT_GREETING) — empty intents would
        // otherwise produce an empty STORE KNOWLEDGE block.
        'intent_content_map' => [
            '_default' => ['page', 'policy', 'blog', 'faq', 'custom', 'product', 'url'],
            'refund_policy' => ['policy', 'faq', 'custom', 'url'],
            'shipping_question' => ['policy', 'faq', 'custom', 'url'],
            'product_support' => ['page', 'blog', 'faq', 'custom', 'product', 'url'],
            'recommendation' => ['blog', 'page', 'faq', 'custom', 'product', 'url'],
            'order_tracking' => ['policy', 'faq', 'custom', 'url'],
            'cart_help' => ['policy', 'faq', 'custom', 'url'],
            'upsell_opportunity' => ['blog', 'faq', 'custom', 'product'],
            'cross_sell_opportunity' => ['blog', 'faq', 'custom', 'product'],
        ],

        // Tunables for the product-knowledge sync (knowledge:sync-products).
        // Pulls products via Storefront GraphQL get_all_products in cursor-
        // paginated pages, dispatches one SummariseKnowledgeItemJob per row.
        'products' => [
            'page_size' => (int) env('SALES_KNOWLEDGE_PRODUCTS_PAGE_SIZE', 50),
            'max_pages' => (int) env('SALES_KNOWLEDGE_PRODUCTS_MAX_PAGES', 10),
        ],

        // Tunables for the URL/sitemap knowledge sync (knowledge:sync-urls).
        // Concurrency is enforced via Http::pool; sitemap_max_urls caps the
        // discovery loop so a misbehaving shop sitemap can't blow up the
        // sync. User agent is required so Shopify storefronts can attribute
        // and rate-limit our requests politely.
        'urls' => [
            'concurrency' => (int) env('SALES_KNOWLEDGE_URL_CONCURRENCY', 4),
            'fetch_timeout' => (int) env('SALES_KNOWLEDGE_URL_TIMEOUT', 15),
            'sitemap_max_urls' => (int) env('SALES_KNOWLEDGE_SITEMAP_MAX', 200),
            'user_agent' => env('SALES_KNOWLEDGE_USER_AGENT', 'ScottStonebridgeBot/1.0 (+https://scottstonebridge.com)'),
        ],

        /*
        | Hybrid retrieval tunables for getKnowledgeForPrompt(). Final
        | score per candidate row is:
        |   semantic_weight  * cosine(query_embedding, row_embedding)
        | + fulltext_weight  * normalised_fulltext_score
        | + recency_weight   * recency_decay(updated_at)
        | + intent_boost     * (1 if row's content_type ∈ intent map else 0)
        | Rows below `min_score` are dropped. `top_n` caps the rows that
        | reach the prompt char budget.
        |
        | `enable_semantic` is a global feature flag. Leave false until
        | embeddings are backfilled, then flip via .env so the picker can
        | use cosine without redeploying.
        */
        'retrieval' => [
            'top_n' => (int) env('SALES_KNOWLEDGE_TOP_N', 8),
            'fulltext_weight' => (float) env('SALES_KNOWLEDGE_FT_WEIGHT', 0.3),
            'semantic_weight' => (float) env('SALES_KNOWLEDGE_SEM_WEIGHT', 0.6),
            'recency_weight' => (float) env('SALES_KNOWLEDGE_REC_WEIGHT', 0.1),
            'intent_boost' => (float) env('SALES_KNOWLEDGE_INTENT_BOOST', 0.15),
            'min_score' => (float) env('SALES_KNOWLEDGE_MIN_SCORE', 0.05),
            'candidate_limit' => (int) env('SALES_KNOWLEDGE_CANDIDATES', 40),
            'recency_half_life_days' => (float) env('SALES_KNOWLEDGE_RECENCY_HALFLIFE', 90.0),
            'enable_semantic' => (bool) env('SALES_KNOWLEDGE_ENABLE_SEMANTIC', false),
        ],

        /*
        | OpenAI embedding tunables. text-embedding-3-small (1536 dim) is
        | the sweet spot for cost ($0.02 per 1M tokens) vs recall. Batched
        | so the backfill command can embed ~50 rows per round-trip.
        */
        'embedding' => [
            'model' => env('SALES_KNOWLEDGE_EMBEDDING_MODEL', 'text-embedding-3-small'),
            'dim' => (int) env('SALES_KNOWLEDGE_EMBEDDING_DIM', 1536),
            'query_cache_ttl' => (int) env('SALES_KNOWLEDGE_EMBEDDING_TTL', 3600),
            'batch_size' => (int) env('SALES_KNOWLEDGE_EMBEDDING_BATCH', 50),
            'batch_sleep_ms' => (int) env('SALES_KNOWLEDGE_EMBEDDING_BATCH_SLEEP_MS', 0),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Conversion Analytics (Phase E)
    |--------------------------------------------------------------------------
    */
    'analytics' => [
        // Allowed event_type values for POST /analytics/event.
        'event_types' => [
            'chat_opened',
            'message_sent',
            'product_clicked',
            'upsell_clicked',
            'upsell_added_to_cart',
            'lead_captured',
            'checkout_started',
            'order_placed',
            'abandon_recovery_sent',
            'trigger_fired',
            'trigger_opened',
            'trigger_dismissed',
            'escalation_triggered',
            'chat_closed',
        ],

        'conversion_types' => ['direct', 'assisted', 'abandoned'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Language Support (Phase F)
    |--------------------------------------------------------------------------
    */
    'locale' => [
        // Fallback locale when no other source resolves.
        'fallback' => env('CHATBOT_FALLBACK_LOCALE', 'en'),

        // Cache key prefix for the per-session locale flag.
        'cache_prefix' => 'ai:locale',

        // Cache TTL (seconds) for the per-session locale flag.
        'cache_ttl' => (int) env('SALES_LOCALE_CACHE_TTL', 7200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Prompt Injection Budget Guard (Phase 2 prompt extensions)
    |--------------------------------------------------------------------------
    |
    | When PromptBuilderService stitches the new upsell + knowledge + locale
    | blocks together, log a warning if the resulting system prompt exceeds
    | this token estimate. Keeps Phase 1 token budget honest.
    |
    */
    'prompt_guard' => [
        'system_prompt_max_tokens' => (int) env('SALES_SYSTEM_PROMPT_MAX_TOKENS', 800),
    ],

];
