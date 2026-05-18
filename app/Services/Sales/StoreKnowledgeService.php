<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Contracts\Services\Sales\StoreKnowledgeServiceInterface;
use App\Contracts\Shopify\AdminApiClientInterface;
use App\Jobs\Sales\SummariseKnowledgeItemJob;
use App\Models\StoreKnowledge;
use App\Services\Base\BaseService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Per-shop knowledge orchestrator.
 *
 *   syncAll($shop)
 *     - Lists Pages + Articles via Admin API (paginated), reads policies
 *       via the existing get_all_policies query.
 *     - Dispatches one SummariseKnowledgeItemJob per record onto the
 *       `sync` queue. Each job calls gpt-4.1-mini once, stores the
 *       summary, and invalidates the Redis index for the shop.
 *
 *   getKnowledgeForPrompt($shop, $intents)
 *     - Reads a per-shop Redis index keyed by intents fingerprint.
 *     - On miss: pulls relevant summaries from DB using
 *       config('sales.knowledge.intent_content_map') and concatenates
 *       up to config('sales.knowledge.prompt_block_max_tokens').
 *
 *   upsertFaq($shop, $question, $answer)
 *     - Inline upsert + cache invalidation. No OpenAI call — FAQ answers
 *       are already authored, just stored verbatim.
 */
class StoreKnowledgeService extends BaseService implements StoreKnowledgeServiceInterface
{
    private const CHARS_PER_TOKEN = 4;

    public function __construct(
        private readonly AdminApiClientInterface $admin,
    ) {
        parent::__construct();
    }

    public function syncAll(string $shopDomain): void
    {
        if ($shopDomain === '') {
            return;
        }

        $pageSize = (int) config('sales.knowledge.admin_page_size', 50);
        $connection = (string) config('sales.queue.connection', 'redis');
        $queue = (string) config('sales.queue.sync', 'sync');

        // Pages
        try {
            $this->forEachAdminPage('admin/pages/list_pages', 'pages', $pageSize, function (array $node) use ($shopDomain, $connection, $queue): void {
                SummariseKnowledgeItemJob::dispatch(
                    $shopDomain,
                    StoreKnowledge::TYPE_PAGE,
                    (string) ($node['title'] ?? 'Untitled page'),
                    (string) ($node['handle'] ?? Str::slug((string) ($node['title'] ?? 'untitled'))),
                    (string) ($node['body'] ?? ''),
                    isset($node['updatedAt']) ? (string) $node['updatedAt'] : null,
                )->onConnection($connection)->onQueue($queue);
            });
        } catch (Throwable $e) {
            $this->logWarning('Knowledge sync: pages list failed', [
                'shop' => $shopDomain,
                'error' => $e->getMessage(),
            ], 'ai');
        }

        // Articles
        try {
            $this->forEachAdminPage('admin/blogs/list_articles', 'articles', $pageSize, function (array $node) use ($shopDomain, $connection, $queue): void {
                $body = trim((string) ($node['body'] ?? $node['summary'] ?? ''));
                $handle = (string) ($node['handle'] ?? '');
                if ($handle === '') {
                    $handle = Str::slug((string) ($node['title'] ?? 'article'));
                }

                SummariseKnowledgeItemJob::dispatch(
                    $shopDomain,
                    StoreKnowledge::TYPE_BLOG,
                    (string) ($node['title'] ?? 'Untitled article'),
                    $handle,
                    $body,
                    isset($node['updatedAt']) ? (string) $node['updatedAt'] : null,
                )->onConnection($connection)->onQueue($queue);
            });
        } catch (Throwable $e) {
            $this->logWarning('Knowledge sync: articles list failed', [
                'shop' => $shopDomain,
                'error' => $e->getMessage(),
            ], 'ai');
        }

        // Policies (single fetch)
        try {
            $response = $this->admin->query('admin/policies/get_all_policies');
            $policies = $response['data']['shop']['shopPolicies'] ?? [];
            if (is_array($policies)) {
                foreach ($policies as $policy) {
                    if (! is_array($policy)) {
                        continue;
                    }
                    SummariseKnowledgeItemJob::dispatch(
                        $shopDomain,
                        StoreKnowledge::TYPE_POLICY,
                        (string) ($policy['title'] ?? 'Policy'),
                        (string) ($policy['type'] ?? $policy['handle'] ?? Str::slug((string) ($policy['title'] ?? 'policy'))),
                        (string) ($policy['body'] ?? ''),
                        isset($policy['updatedAt']) ? (string) $policy['updatedAt'] : null,
                    )->onConnection($connection)->onQueue($queue);
                }
            }
        } catch (Throwable $e) {
            $this->logWarning('Knowledge sync: policies fetch failed', [
                'shop' => $shopDomain,
                'error' => $e->getMessage(),
            ], 'ai');
        }
    }

    public function getKnowledgeForPrompt(string $shopDomain, array $intents): string
    {
        if ($shopDomain === '' || $intents === []) {
            return '';
        }

        $map = (array) config('sales.knowledge.intent_content_map', []);
        $types = [];
        foreach ($intents as $intent) {
            foreach ((array) ($map[$intent] ?? []) as $type) {
                $types[$type] = true;
            }
        }
        if ($types === []) {
            return '';
        }

        $cacheKey = sprintf(
            'ai:knowledge:%s:%s',
            $shopDomain,
            md5(implode(',', array_keys($types))),
        );
        $ttl = (int) config('sales.knowledge.cache_ttl', 86400);
        $maxChars = (int) config('sales.knowledge.prompt_block_max_tokens', 500) * self::CHARS_PER_TOKEN;

        return Cache::remember($cacheKey, $ttl, function () use ($shopDomain, $types, $maxChars): string {
            $rows = StoreKnowledge::query()
                ->forShop($shopDomain)
                ->forTypes(array_keys($types))
                ->orderBy('content_type')
                ->orderBy('updated_at', 'desc')
                ->limit(20)
                ->get(['content_type', 'title', 'summary']);

            if ($rows->isEmpty()) {
                return '';
            }

            $lines = [];
            $charsUsed = 0;
            foreach ($rows as $row) {
                $line = sprintf('- [%s] %s — %s', $row->content_type, $row->title, $row->summary);
                $charsUsed += mb_strlen($line) + 1;
                if ($charsUsed > $maxChars) {
                    break;
                }
                $lines[] = $line;
            }

            return implode("\n", $lines);
        });
    }

    public function invalidateCache(string $shopDomain): void
    {
        if ($shopDomain === '') {
            return;
        }

        // Cache keys are deterministic per (shop, intent-fingerprint).
        // Flushing the per-shop keys we know about is enough — distinct
        // fingerprints get their own keys and naturally expire on TTL.
        $map = (array) config('sales.knowledge.intent_content_map', []);
        $fingerprints = [];
        foreach ($map as $types) {
            $fingerprints[] = md5(implode(',', array_unique((array) $types)));
        }
        foreach (array_unique($fingerprints) as $fp) {
            Cache::forget(sprintf('ai:knowledge:%s:%s', $shopDomain, $fp));
        }
    }

    public function upsertFaq(string $shopDomain, string $question, string $answer): StoreKnowledge
    {
        $shopDomain = trim($shopDomain);
        $question = trim($question);
        $answer = trim($answer);

        $handle = Str::slug($question, '-');
        if ($handle === '') {
            $handle = 'faq-'.substr(md5($question), 0, 8);
        }

        $summary = mb_strimwidth($answer, 0, 1200, '…');

        $faq = StoreKnowledge::query()
            ->updateOrCreate(
                [
                    'shop_domain' => $shopDomain,
                    'content_type' => StoreKnowledge::TYPE_FAQ,
                    'handle' => $handle,
                ],
                [
                    'title' => $question,
                    'summary' => $summary,
                    'raw_content' => $answer,
                    'last_synced_at' => now(),
                    'shopify_updated_at' => null,
                ],
            );

        $this->invalidateCache($shopDomain);

        return $faq;
    }

    /**
     * Walk a paginated Admin API connection and invoke $handler($node) for
     * every node. Stops at config('sales.knowledge.admin_page_size') items
     * per page; loops until pageInfo.hasNextPage is false or 10 pages,
     * whichever comes first.
     *
     * @param  callable(array<string, mixed>): void  $handler
     */
    private function forEachAdminPage(string $queryPath, string $rootField, int $pageSize, callable $handler): void
    {
        $cursor = null;
        for ($i = 0; $i < 10; $i++) {
            $response = $this->admin->query($queryPath, [
                'first' => $pageSize,
                'after' => $cursor,
            ]);

            $connection = $response['data'][$rootField] ?? [];
            $edges = $connection['edges'] ?? [];
            if (! is_array($edges) || $edges === []) {
                return;
            }

            foreach ($edges as $edge) {
                $node = $edge['node'] ?? null;
                if (is_array($node)) {
                    $handler($node);
                }
            }

            $pageInfo = $connection['pageInfo'] ?? [];
            if (empty($pageInfo['hasNextPage'])) {
                return;
            }

            $cursor = (string) ($pageInfo['endCursor'] ?? '');
            if ($cursor === '') {
                return;
            }
        }
    }
}
