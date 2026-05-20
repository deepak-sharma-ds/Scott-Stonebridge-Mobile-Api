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
     * @param  list<string>  $intents
     */
    public function getKnowledgeForPrompt(string $shopDomain, array $intents): string;

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
}
