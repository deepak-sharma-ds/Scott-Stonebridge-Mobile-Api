<?php

use App\Models\Configuration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Data-only migration: inserts the chatbot's tunable values (rate limits,
 * MCP retry/backoff, cache TTLs, intent-confidence thresholds, truncation
 * lengths, currency→country map) as `Chatbot.<Group>.<key>` rows in the
 * existing `configurations` table.
 *
 * Deliberately does not alter the `configurations` schema, the
 * `Configuration` model, or `AppServiceProvider` — see ADR 0006. Values are
 * read exclusively through `App\Services\AI\ChatbotConfigRepository`.
 */
return new class extends Migration
{
    /**
     * @return list<array{name: string, value: string, title: string, description: string, input_type: string}>
     */
    private function rows(): array
    {
        return [
            // RateLimit
            ['name' => 'Chatbot.RateLimit.stream_per_minute', 'value' => '30', 'title' => 'Chat Stream Rate Limit (per minute)', 'description' => 'Max SSE stream requests per minute per chat session.', 'input_type' => 'text'],
            ['name' => 'Chatbot.RateLimit.triggers_per_minute', 'value' => '30', 'title' => 'Proactive Triggers Rate Limit (per minute)', 'description' => 'Max proactive-trigger poll requests per minute per IP.', 'input_type' => 'text'],
            ['name' => 'Chatbot.RateLimit.triggers_event_per_minute', 'value' => '60', 'title' => 'Trigger Event Rate Limit (per minute)', 'description' => 'Max trigger open/dismiss events per minute per session.', 'input_type' => 'text'],
            ['name' => 'Chatbot.RateLimit.lead_capture_per_minute', 'value' => '5', 'title' => 'Lead Capture Rate Limit (per minute)', 'description' => 'Max lead-capture submissions per minute per session.', 'input_type' => 'text'],
            ['name' => 'Chatbot.RateLimit.upsell_per_minute', 'value' => '20', 'title' => 'Upsell Rate Limit (per minute)', 'description' => 'Max upsell-suggestion requests per minute per session.', 'input_type' => 'text'],
            ['name' => 'Chatbot.RateLimit.analytics_event_per_minute', 'value' => '120', 'title' => 'Analytics Event Rate Limit (per minute)', 'description' => 'Max analytics events per minute per session.', 'input_type' => 'text'],
            ['name' => 'Chatbot.RateLimit.knowledge_per_minute', 'value' => '30', 'title' => 'Knowledge Endpoint Rate Limit (per minute)', 'description' => 'Max knowledge FAQ upsert requests per minute per IP.', 'input_type' => 'text'],
            ['name' => 'Chatbot.RateLimit.oauth_start_per_minute', 'value' => '10', 'title' => 'OAuth Start Rate Limit (per minute)', 'description' => 'Max Customer Account OAuth start requests per minute per IP.', 'input_type' => 'text'],
            ['name' => 'Chatbot.RateLimit.mcp_per_minute', 'value' => '60', 'title' => 'MCP Tool Call Rate Limit (per minute)', 'description' => 'Max MCP tool calls per minute per session.', 'input_type' => 'text'],

            // Streaming
            ['name' => 'Chatbot.Streaming.retry_backoff_base_ms', 'value' => '200', 'title' => 'Stream Retry Backoff Base (ms)', 'description' => 'Base backoff, multiplied by attempt number, before retrying a failed stream establishment.', 'input_type' => 'text'],

            // Mcp
            ['name' => 'Chatbot.Mcp.retry_count', 'value' => '2', 'title' => 'MCP HTTP Retry Count', 'description' => 'Number of retries for a failed MCP tool call.', 'input_type' => 'text'],
            ['name' => 'Chatbot.Mcp.retry_delay_ms', 'value' => '200', 'title' => 'MCP HTTP Retry Delay (ms)', 'description' => 'Delay between MCP tool call retries.', 'input_type' => 'text'],
            ['name' => 'Chatbot.Mcp.discovery_cache_ttl_seconds', 'value' => '3600', 'title' => 'MCP Discovery Cache TTL (seconds)', 'description' => 'How long the Customer Account API discovery document is cached.', 'input_type' => 'text'],

            // Recommendation
            ['name' => 'Chatbot.Recommendation.cache_ttl_seconds', 'value' => '300', 'title' => 'Product Recommendation Cache TTL (seconds)', 'description' => 'How long a product recommendation search result is cached.', 'input_type' => 'text'],
            ['name' => 'Chatbot.Recommendation.max_keywords', 'value' => '6', 'title' => 'Product Recommendation Max Keywords', 'description' => 'Max keywords kept when building a Shopify search query from the user message.', 'input_type' => 'text'],

            // Intent
            ['name' => 'Chatbot.Intent.fastpath_confidence', 'value' => '0.85', 'title' => 'Intent Fast-Path Confidence', 'description' => 'Confidence assigned to a keyword/regex intent match.', 'input_type' => 'text'],
            ['name' => 'Chatbot.Intent.unknown_default_confidence', 'value' => '0.3', 'title' => 'Intent Unknown Default Confidence', 'description' => 'Confidence assigned when no keyword matched and no page-type prior applies.', 'input_type' => 'text'],
            ['name' => 'Chatbot.Intent.pagetype_default_confidence', 'value' => '0.55', 'title' => 'Intent Page-Type Default Confidence', 'description' => 'Confidence assigned when no keyword matched but a page-type prior applies.', 'input_type' => 'text'],
            ['name' => 'Chatbot.Intent.crosssell_prior_confidence', 'value' => '0.7', 'title' => 'Intent Cross-Sell Prior Confidence', 'description' => 'Confidence assigned when the cross-sell-opportunity context prior fires.', 'input_type' => 'text'],
            ['name' => 'Chatbot.Intent.malformed_fallback_confidence', 'value' => '0.6', 'title' => 'Intent Classifier Malformed-Response Confidence', 'description' => 'Confidence used when the classifier response omits a confidence value.', 'input_type' => 'text'],
            ['name' => 'Chatbot.Intent.error_fallback_min_confidence', 'value' => '0.5', 'title' => 'Intent Classifier Error Fallback Minimum Confidence', 'description' => 'Minimum confidence used when the classifier call fails outright.', 'input_type' => 'text'],

            // Context
            ['name' => 'Chatbot.Context.policy_summary_max_chars', 'value' => '600', 'title' => 'Policy Summary Max Characters', 'description' => 'Max characters kept when summarising a Shopify policy for the prompt.', 'input_type' => 'text'],
            ['name' => 'Chatbot.Context.product_description_max_chars', 'value' => '400', 'title' => 'Product Description Max Characters', 'description' => 'Max characters kept when summarising a product description for the prompt.', 'input_type' => 'text'],
            ['name' => 'Chatbot.Context.currency_country_map', 'value' => '{"USD":"US","EUR":"DE","CAD":"CA","AUD":"AU","INR":"IN"}', 'title' => 'Currency to Country Map', 'description' => 'JSON map of ISO currency code to ISO country code, used to localise Shopify catalog lookups.', 'input_type' => 'text'],
            ['name' => 'Chatbot.Context.currency_country_fallback', 'value' => 'GB', 'title' => 'Currency to Country Fallback', 'description' => 'Country code used when the currency is not in the currency-country map.', 'input_type' => 'text'],

            // ToolExecutor
            ['name' => 'Chatbot.ToolExecutor.rate_limit_ttl_seconds', 'value' => '60', 'title' => 'Tool Executor Rate Limit TTL (seconds)', 'description' => 'Sliding window for the per-session MCP tool-call rate bucket.', 'input_type' => 'text'],
            ['name' => 'Chatbot.ToolExecutor.rate_limit_max', 'value' => '60', 'title' => 'Tool Executor Rate Limit Max', 'description' => 'Max MCP tool calls allowed per session within the rate limit TTL.', 'input_type' => 'text'],
            ['name' => 'Chatbot.ToolExecutor.session_cart_ttl_seconds', 'value' => '604800', 'title' => 'Session Cart TTL (seconds)', 'description' => 'How long a chat session remembers the Shopify cart GID it created.', 'input_type' => 'text'],

            // Personalization
            ['name' => 'Chatbot.Personalization.cache_ttl_seconds', 'value' => '1800', 'title' => 'Customer Personalization Cache TTL (seconds)', 'description' => 'How long a signed-in customer\'s order summary is cached.', 'input_type' => 'text'],
            ['name' => 'Chatbot.Personalization.recent_orders_limit', 'value' => '3', 'title' => 'Customer Personalization Recent Orders Limit', 'description' => 'Max recent orders fetched for the personalisation summary.', 'input_type' => 'text'],
        ];
    }

    public function up(): void
    {
        // `configurations` is a legacy table with no schema migration of its
        // own (see ADR 0006) — some environments (fresh test DBs) won't have
        // it. Skip seeding rather than fail the whole migration batch,
        // matching AppServiceProvider::configHandler()'s own tolerance for
        // this table being absent.
        if (! Schema::hasTable('configurations')) {
            return;
        }

        foreach ($this->rows() as $row) {
            Configuration::query()->updateOrCreate(
                ['name' => $row['name']],
                $row + ['editable' => 1],
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('configurations')) {
            return;
        }

        Configuration::query()
            ->where('name', 'like', 'Chatbot.%')
            ->delete();
    }
};
