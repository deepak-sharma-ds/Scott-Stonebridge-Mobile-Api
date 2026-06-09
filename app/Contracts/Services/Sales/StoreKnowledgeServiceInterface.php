<?php

declare(strict_types=1);

namespace App\Contracts\Services\Sales;

use App\Models\StoreKnowledge;

/**
 * Per-shop knowledge base orchestrator. Schedules pages/policies/blog
 * sync from Shopify Admin API, summarises each item with OpenAI, caches
 * the per-intent assembled block in Redis, and exposes a synchronous
 * upsertFaq() for merchant-tooling input.
 *
 * Returns plain strings rather than DTOs because every consumer just
 * concatenates into the prompt.
 */
interface StoreKnowledgeServiceInterface
{
    /**
     * Paginate Shopify Admin API for pages + blog articles, fetch
     * policies, and dispatch one SummariseKnowledgeItemJob per record.
     * The job upserts a row in store_knowledge with the AI summary.
     * Idempotent — re-runs are safe.
     */
    public function syncAll(string $shopDomain): void;

    /**
     * Return a single string containing relevant summaries for the given
     * detected intents. Reads the Redis index first; rebuilds from DB on
     * miss. Output is bounded by config('sales.knowledge.prompt_block_max_tokens').
     *
     * When `$userQuery` is non-empty the picker switches to hybrid
     * keyword + semantic relevance ranking and only falls back to the
     * pure intent → recency path when ranking yields no candidates.
     *
     * @param  list<string>  $intents
     */
    public function getKnowledgeForPrompt(string $shopDomain, array $intents, ?string $userQuery = null): string;

    /**
     * Return ranked knowledge rows for the LLM-callable
     * `search_knowledge_base` tool. Same hybrid ranking pipeline as
     * `getKnowledgeForPrompt` but returns structured rows (not the
     * concatenated prompt block) so ToolExecutor can hand them to the
     * model verbatim.
     *
     * @param  list<string>|null  $contentTypes  Optional content_type filter
     * @return list<array{title:string, content_type:string, handle:string|null, summary:string, score:float}>
     */
    public function searchForTool(string $shopDomain, string $query, ?array $contentTypes = null, int $limit = 5): array;

    /**
     * Drop the Redis index for the shop so the next prompt call rebuilds
     * from DB. Called by the per-item summary job after upsert.
     */
    public function invalidateCache(string $shopDomain): void;

    /**
     * Upsert a merchant-provided FAQ. Runs inline (no queued summarisation
     * job) because FAQ inputs are short.
     */
    public function upsertFaq(string $shopDomain, string $question, string $answer): StoreKnowledge;

    /**
     * Pull products from Shopify Storefront GraphQL (cursor paginated) and
     * dispatch one SummariseKnowledgeItemJob per product so each row gets
     * a compact AI summary covering descriptionHtml + vendor/type/tags.
     * Returns the number of products dispatched. Idempotent by handle.
     */
    public function syncProducts(string $shopDomain, ?int $limit = null): int;

    /**
     * Fetch every URL in the supplied list, strip the HTML to readable text,
     * and dispatch one SummariseKnowledgeItemJob per URL. Handle is derived
     * from md5(url) so reruns are idempotent. Returns the number of URLs
     * dispatched.
     *
     * @param  list<string>  $urls
     */
    public function syncUrls(string $shopDomain, array $urls): int;

    /**
     * Walk https://{shop}/sitemap.xml (and any nested sitemap children)
     * and return the URLs found. Capped at
     * config('sales.knowledge.urls.sitemap_max_urls').
     *
     * @return list<string>
     */
    public function discoverSitemapUrls(string $shopDomain): array;
}
