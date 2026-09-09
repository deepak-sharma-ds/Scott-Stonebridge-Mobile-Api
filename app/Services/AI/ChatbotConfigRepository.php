<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Configuration;
use App\Services\CurrencyCountryMapService;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Single typed read path for chatbot tunables stored in the legacy
 * `configurations` table under the `Chatbot.<Group>.<key>` naming
 * convention (e.g. `Chatbot.RateLimit.stream_per_minute`).
 *
 * Reads exclusively through the unmodified `Configuration::getConfig()` and
 * casts each value to its real type here — `Configuration`, its admin
 * controller, and `AppServiceProvider::configHandler()` are deliberately
 * left untouched. See ADR 0006.
 *
 * Falls back to the value the code previously had hardcoded whenever a row
 * hasn't been created yet in the admin panel, so behaviour is unchanged
 * until an admin actually edits a setting.
 */
class ChatbotConfigRepository
{
    private const PREFIX = 'Chatbot.';

    /** @var array<string, string> */
    private const DEFAULT_CURRENCY_COUNTRY_MAP = [
        'USD' => 'US',
        'EUR' => 'DE',
        'CAD' => 'CA',
        'AUD' => 'AU',
        'INR' => 'IN',
    ];

    private const DEFAULT_CURRENCY_COUNTRY_FALLBACK = 'GB';

    // -- RateLimit ---------------------------------------------------------

    public function rateLimitStreamPerMinute(): int
    {
        return $this->int('RateLimit.stream_per_minute', 30);
    }

    public function rateLimitTriggersPerMinute(): int
    {
        return $this->int('RateLimit.triggers_per_minute', 30);
    }

    public function rateLimitTriggersEventPerMinute(): int
    {
        return $this->int('RateLimit.triggers_event_per_minute', 60);
    }

    public function rateLimitLeadCapturePerMinute(): int
    {
        return $this->int('RateLimit.lead_capture_per_minute', 5);
    }

    public function rateLimitUpsellPerMinute(): int
    {
        return $this->int('RateLimit.upsell_per_minute', 20);
    }

    public function rateLimitAnalyticsEventPerMinute(): int
    {
        return $this->int('RateLimit.analytics_event_per_minute', 120);
    }

    public function rateLimitKnowledgePerMinute(): int
    {
        return $this->int('RateLimit.knowledge_per_minute', 30);
    }

    public function rateLimitOauthStartPerMinute(): int
    {
        return $this->int('RateLimit.oauth_start_per_minute', 10);
    }

    public function rateLimitMcpPerMinute(): int
    {
        return $this->int('RateLimit.mcp_per_minute', 60);
    }

    // -- Streaming -----------------------------------------------------------

    public function streamingRetryBackoffBaseMs(): int
    {
        return $this->int('Streaming.retry_backoff_base_ms', 200);
    }

    // -- Mcp -----------------------------------------------------------------

    public function mcpRetryCount(): int
    {
        return $this->int('Mcp.retry_count', 2);
    }

    public function mcpRetryDelayMs(): int
    {
        return $this->int('Mcp.retry_delay_ms', 200);
    }

    public function mcpDiscoveryCacheTtlSeconds(): int
    {
        return $this->int('Mcp.discovery_cache_ttl_seconds', 3600);
    }

    // -- Recommendation --------------------------------------------------------

    public function recommendationCacheTtlSeconds(): int
    {
        return $this->int('Recommendation.cache_ttl_seconds', 300);
    }

    public function recommendationMaxKeywords(): int
    {
        return $this->int('Recommendation.max_keywords', 6);
    }

    // -- Intent ----------------------------------------------------------------

    public function intentFastpathConfidence(): float
    {
        return $this->float('Intent.fastpath_confidence', 0.85);
    }

    public function intentUnknownDefaultConfidence(): float
    {
        return $this->float('Intent.unknown_default_confidence', 0.3);
    }

    public function intentPageTypeDefaultConfidence(): float
    {
        return $this->float('Intent.pagetype_default_confidence', 0.55);
    }

    public function intentCrossSellPriorConfidence(): float
    {
        return $this->float('Intent.crosssell_prior_confidence', 0.7);
    }

    public function intentMalformedFallbackConfidence(): float
    {
        return $this->float('Intent.malformed_fallback_confidence', 0.6);
    }

    public function intentErrorFallbackMinConfidence(): float
    {
        return $this->float('Intent.error_fallback_min_confidence', 0.5);
    }

    // -- Context -----------------------------------------------------------------

    public function contextPolicySummaryMaxChars(): int
    {
        return $this->int('Context.policy_summary_max_chars', 600);
    }

    public function contextProductDescriptionMaxChars(): int
    {
        return $this->int('Context.product_description_max_chars', 400);
    }

    public function countryFromCurrency(?string $currency): string
    {
        $map = $this->json('Context.currency_country_map', null);
        if ($map !== null && isset($map[strtoupper((string) $currency)])) {
            return $map[strtoupper((string) $currency)];
        }

        $fallback = $this->raw('Context.currency_country_fallback');
        if ($fallback !== null && $fallback !== '') {
            return (string) $fallback;
        }

        return CurrencyCountryMapService::getCountryCode((string) ($currency ?? 'GBP'));
    }

    // -- ToolExecutor ------------------------------------------------------------

    public function toolExecutorRateLimitTtlSeconds(): int
    {
        return $this->int('ToolExecutor.rate_limit_ttl_seconds', 60);
    }

    public function toolExecutorRateLimitMax(): int
    {
        return $this->int('ToolExecutor.rate_limit_max', 60);
    }

    public function toolExecutorSessionCartTtlSeconds(): int
    {
        return $this->int('ToolExecutor.session_cart_ttl_seconds', 604800);
    }

    // -- Personalization -----------------------------------------------------------

    public function personalizationCacheTtlSeconds(): int
    {
        return $this->int('Personalization.cache_ttl_seconds', 1800);
    }

    public function personalizationRecentOrdersLimit(): int
    {
        return $this->int('Personalization.recent_orders_limit', 3);
    }

    // -- casting helpers -----------------------------------------------------------

    /**
     * Falls back to the hardcoded default (via the caller) whenever the
     * legacy `configurations` table is unavailable — same tolerance
     * `AppServiceProvider::configHandler()` already has for this table,
     * needed because it has no migration file of its own (see ADR 0006).
     */
    private function raw(string $key): ?string
    {
        try {
            if (! Schema::hasTable('configurations')) {
                return null;
            }

            $value = Configuration::getConfig(self::PREFIX.$key);
        } catch (Throwable) {
            return null;
        }

        return $value === '' ? null : (string) $value;
    }

    private function int(string $key, int $default): int
    {
        $raw = $this->raw($key);

        return $raw === null ? $default : (int) $raw;
    }

    private function float(string $key, float $default): float
    {
        $raw = $this->raw($key);

        return $raw === null ? $default : (float) $raw;
    }

    /**
     * @param  array<string, string>  $default
     * @return array<string, string>
     */
    private function json(string $key, array $default): array
    {
        $raw = $this->raw($key);
        if ($raw === null) {
            return $default;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : $default;
    }
}
