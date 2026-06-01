<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Contracts\Services\Sales\StoreKnowledgeServiceInterface;
use App\Contracts\Shopify\AdminApiClientInterface;
use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\Jobs\Sales\SummariseKnowledgeItemJob;
use App\Models\StoreKnowledge;
use App\Services\Base\BaseService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;
use SimpleXMLElement;
use Symfony\Component\DomCrawler\Crawler;
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
        // Nullable so legacy test factories that hand-construct the
        // service with only the admin client still pass. Production
        // wiring resolves both via the container.
        private readonly ?StorefrontApiClientInterface $storefront = null,
    ) {
        parent::__construct();
    }

    /**
     * Lazy-resolve the storefront client. Throws if a caller invokes a
     * URL/product path on an instance built without one, but never
     * triggers during construction so existing tests keep working.
     */
    private function storefront(): StorefrontApiClientInterface
    {
        return $this->storefront ?? app(StorefrontApiClientInterface::class);
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

    public function getKnowledgeForPrompt(string $shopDomain, array $intents, ?string $userQuery = null): string
    {
        if ($shopDomain === '' || $intents === []) {
            return '';
        }

        $intentTypes = $this->resolveIntentTypes($intents);
        $userQuery = $userQuery !== null ? trim($userQuery) : '';

        // No intent types AND no query → nothing to rank against. Bail
        // out so unknown intents don't drag every shop row into the
        // prompt. Production config always supplies a non-empty
        // `_default` bucket so this guard is only hit when consumers
        // (or unit tests) deliberately leave the map narrow.
        if ($intentTypes === [] && $userQuery === '') {
            return '';
        }

        $maxChars = (int) config('sales.knowledge.prompt_block_max_tokens', 500) * self::CHARS_PER_TOKEN;
        $topN = max(1, (int) config('sales.knowledge.retrieval.top_n', 8));
        $ttl = (int) config('sales.knowledge.cache_ttl', 86400);

        // Cache key folds in both the type-fingerprint AND a hash of the
        // user query so two different questions don't share the same
        // ranked block. Empty query path keeps the legacy fingerprint so
        // greeting / context-free turns share one cache entry.
        $cacheKey = sprintf(
            'ai:knowledge:%s:%s:q%s',
            $shopDomain,
            md5(implode(',', $intentTypes)),
            $userQuery === '' ? 'none' : substr(md5($userQuery), 0, 12),
        );

        return Cache::remember($cacheKey, $ttl, function () use ($shopDomain, $intentTypes, $userQuery, $maxChars, $topN): string {
            $rows = $this->rankedRows($shopDomain, $intentTypes, $userQuery, $topN);
            if ($rows === []) {
                return '';
            }

            $lines = [];
            $charsUsed = 0;
            foreach ($rows as $row) {
                $line = sprintf('- [%s] %s — %s', $row['content_type'], $row['title'], $row['summary']);
                $charsUsed += mb_strlen($line) + 1;
                if ($charsUsed > $maxChars) {
                    break;
                }
                $lines[] = $line;
            }

            return implode("\n", $lines);
        });
    }

    public function searchForTool(string $shopDomain, string $query, ?array $contentTypes = null, int $limit = 5): array
    {
        $shopDomain = trim($shopDomain);
        $query = trim($query);
        if ($shopDomain === '' || $query === '') {
            return [];
        }

        // Tool calls intentionally bypass the intent map — the LLM has
        // already decided this is a knowledge question. `contentTypes`
        // is an optional narrowing hint from the tool arguments.
        $types = $contentTypes !== null
            ? array_values(array_filter(array_map('strval', $contentTypes), static fn ($t) => $t !== ''))
            : [];

        $limit = max(1, min($limit, 8));

        return $this->rankedRows($shopDomain, $types, $query, $limit);
    }

    public function invalidateCache(string $shopDomain): void
    {
        if ($shopDomain === '') {
            return;
        }

        // The new cache keys layer a per-query suffix on top of the
        // type fingerprint so we can't enumerate them all. Use the
        // shop-scoped tagged store when available; otherwise flush the
        // fingerprint slots (per-query keys expire naturally on TTL).
        $map = (array) config('sales.knowledge.intent_content_map', []);
        $fingerprints = [];
        foreach ($map as $types) {
            $fingerprints[] = md5(implode(',', array_unique((array) $types)));
        }
        $fingerprints[] = md5(''); // empty-types fingerprint for safety
        foreach (array_unique($fingerprints) as $fp) {
            Cache::forget(sprintf('ai:knowledge:%s:%s:qnone', $shopDomain, $fp));
            // Backwards-compatibility with the previous cache shape
            // (no per-query suffix) so a deploy doesn't strand the old
            // entries.
            Cache::forget(sprintf('ai:knowledge:%s:%s', $shopDomain, $fp));
        }
    }

    /**
     * Resolve the user-supplied intents into a list of content_type
     * values. Falls through to the `_default` bucket when no intent
     * maps so unknown / greeting turns still see knowledge rows.
     *
     * @param  list<string>  $intents
     * @return list<string>
     */
    private function resolveIntentTypes(array $intents): array
    {
        $map = (array) config('sales.knowledge.intent_content_map', []);
        $types = [];
        foreach ($intents as $intent) {
            foreach ((array) ($map[$intent] ?? []) as $type) {
                $types[$type] = true;
            }
        }

        if ($types === []) {
            foreach ((array) ($map['_default'] ?? []) as $type) {
                $types[$type] = true;
            }
        }

        return array_keys($types);
    }

    /**
     * Hybrid retrieval pipeline. Returns at most `$topN` rows scored by
     * keyword (FULLTEXT) + semantic (cosine) + recency + intent boost.
     * Falls back to the legacy intent + recency path when no query is
     * supplied or when ranking produces zero candidates.
     *
     * @param  list<string>  $intentTypes
     * @return list<array{content_type:string, title:string, summary:string, handle:string|null, score:float}>
     */
    private function rankedRows(string $shopDomain, array $intentTypes, string $userQuery, int $topN): array
    {
        $weights = (array) config('sales.knowledge.retrieval', []);
        $wFulltext = (float) ($weights['fulltext_weight'] ?? 0.3);
        $wSemantic = (float) ($weights['semantic_weight'] ?? 0.6);
        $wRecency = (float) ($weights['recency_weight'] ?? 0.1);
        $intentBoost = (float) ($weights['intent_boost'] ?? 0.15);
        $minScore = (float) ($weights['min_score'] ?? 0.05);
        $candidateLimit = max($topN, (int) ($weights['candidate_limit'] ?? 40));
        $semanticEnabled = (bool) ($weights['enable_semantic'] ?? false);

        // Step 1 — assemble candidate set. With a user query we pull
        // the best FULLTEXT matches AND every embedded row so the
        // semantic layer can rescue rows whose keywords lost out to
        // term-frequency noise ("Scott Stonebridge" appears in dozens
        // of URL titles so FULLTEXT alone can drown the actual bio).
        // Without a query we grab the most recent rows in the intent
        // bucket (legacy behaviour).
        $semanticEnabled = $semanticEnabled && $userQuery !== '';

        $candidates = $userQuery !== ''
            ? $this->searchByKeyword($shopDomain, $userQuery, $candidateLimit)
            : new Collection;

        if ($semanticEnabled) {
            $embedded = StoreKnowledge::query()
                ->forShop($shopDomain)
                ->whereNotNull('embedding')
                ->get(['id', 'content_type', 'title', 'handle', 'summary', 'embedding', 'updated_at']);
            $candidates = $candidates->concat($embedded)->unique('id')->values();
        }

        if ($candidates->isEmpty() || $userQuery === '') {
            $fallback = StoreKnowledge::query()
                ->forShop($shopDomain)
                ->when($intentTypes !== [], fn ($q) => $q->forTypes($intentTypes))
                ->orderBy('updated_at', 'desc')
                ->limit($candidateLimit)
                ->get(['id', 'content_type', 'title', 'handle', 'summary', 'embedding', 'updated_at']);
            $candidates = $candidates->concat($fallback)->unique('id')->values();
        }

        if ($candidates->isEmpty()) {
            return [];
        }

        // Step 2 — normalise FULLTEXT scores into 0..1 across the
        // candidate set so they're comparable to cosine + recency.
        $maxFt = (float) ($candidates->max('ft_score') ?? 0.0);
        if ($maxFt <= 0.0) {
            $maxFt = 1.0;
        }

        // Step 3 — optional semantic layer.
        $queryEmbedding = null;
        if ($semanticEnabled && $userQuery !== '') {
            $queryEmbedding = $this->embedQuery($userQuery);
        }

        // Step 4 — score every candidate.
        $halfLifeDays = max(1.0, (float) ($weights['recency_half_life_days'] ?? 90.0));
        $now = now();
        $intentSet = array_flip($intentTypes);
        $scored = [];

        foreach ($candidates as $row) {
            $ftScore = (float) ($row->ft_score ?? 0.0) / $maxFt;

            $semScore = 0.0;
            if ($queryEmbedding !== null) {
                $emb = $row->embedding;
                if (is_array($emb) && $emb !== []) {
                    $semScore = max(0.0, $this->cosine($queryEmbedding, $emb));
                }
            }

            $updatedAt = $row->updated_at;
            $recency = 0.0;
            if ($updatedAt !== null) {
                $ageDays = max(0.0, $now->diffInDays($updatedAt, true));
                $recency = exp(-($ageDays / $halfLifeDays));
            }

            $boost = isset($intentSet[$row->content_type]) ? $intentBoost : 0.0;

            $score = ($wFulltext * $ftScore)
                + ($wSemantic * $semScore)
                + ($wRecency * $recency)
                + $boost;

            if ($score < $minScore) {
                continue;
            }

            $scored[] = [
                'content_type' => (string) $row->content_type,
                'title' => (string) $row->title,
                'handle' => $row->handle !== null ? (string) $row->handle : null,
                'summary' => (string) $row->summary,
                'score' => round($score, 4),
                '_id' => (int) $row->id,
            ];
        }

        if ($scored === []) {
            return [];
        }

        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);
        $scored = array_slice($scored, 0, $topN);
        foreach ($scored as &$row) {
            unset($row['_id']);
        }

        /** @var list<array{content_type:string, title:string, summary:string, handle:string|null, score:float}> $scored */
        return $scored;
    }

    /**
     * FULLTEXT keyword search across (title, summary). On SQLite (test
     * env) — and any other driver without MATCH AGAINST — falls back to
     * a LIKE-based scoring so the pipeline keeps working without an
     * index. The fallback emits an `ft_score` based on substring hit
     * count so downstream normalisation still has a meaningful number.
     *
     * @return Collection<int, StoreKnowledge>
     */
    private function searchByKeyword(string $shopDomain, string $query, int $candidateLimit): Collection
    {
        $driver = StoreKnowledge::query()->getConnection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            try {
                return StoreKnowledge::query()
                    ->forShop($shopDomain)
                    ->selectRaw('store_knowledge.*, MATCH(title, summary) AGAINST (? IN NATURAL LANGUAGE MODE) AS ft_score', [$query])
                    ->whereRaw('MATCH(title, summary) AGAINST (? IN NATURAL LANGUAGE MODE) > 0', [$query])
                    ->orderByRaw('ft_score DESC')
                    ->limit($candidateLimit)
                    ->get();
            } catch (Throwable $e) {
                Log::channel('ai')->info('knowledge: fulltext query failed; falling back to LIKE', [
                    'shop' => $shopDomain,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // LIKE-based fallback. Tokenise the query, count substring hits
        // in title (weight 2) and summary (weight 1) to emulate a
        // relevance score.
        $tokens = array_values(array_filter(preg_split('/\s+/u', $query) ?: [], static fn ($t) => mb_strlen((string) $t) >= 2));
        if ($tokens === []) {
            return new Collection;
        }

        $rows = StoreKnowledge::query()
            ->forShop($shopDomain)
            ->where(function ($q) use ($tokens): void {
                foreach ($tokens as $token) {
                    $q->orWhere('title', 'like', '%'.$token.'%')
                        ->orWhere('summary', 'like', '%'.$token.'%');
                }
            })
            ->limit($candidateLimit)
            ->get();

        foreach ($rows as $row) {
            $score = 0;
            $titleLc = mb_strtolower((string) $row->title);
            $summaryLc = mb_strtolower((string) $row->summary);
            foreach ($tokens as $token) {
                $tLc = mb_strtolower((string) $token);
                $score += substr_count($titleLc, $tLc) * 2;
                $score += substr_count($summaryLc, $tLc);
            }
            $row->ft_score = $score > 0 ? (float) $score : 0.0;
        }

        return $rows->sortByDesc('ft_score')->values();
    }

    /**
     * Embed the user query with the OpenAI embeddings API and cache the
     * result for `query_cache_ttl` seconds so repeat questions skip the
     * round-trip. Returns null on failure so the caller silently falls
     * back to keyword-only scoring.
     *
     * @return list<float>|null
     */
    private function embedQuery(string $query): ?array
    {
        $model = (string) config('sales.knowledge.embedding.model', 'text-embedding-3-small');
        $ttl = (int) config('sales.knowledge.embedding.query_cache_ttl', 3600);
        $cacheKey = sprintf('ai:knowledge:qemb:%s:%s', $model, md5($query));

        return Cache::remember($cacheKey, $ttl, function () use ($query, $model): ?array {
            try {
                $response = OpenAI::embeddings()->create([
                    'model' => $model,
                    'input' => $query,
                ]);
                $vector = $response->embeddings[0]->embedding ?? null;
                if (! is_array($vector) || $vector === []) {
                    return null;
                }

                return array_map('floatval', $vector);
            } catch (Throwable $e) {
                Log::channel('ai')->warning('knowledge: query embedding failed', [
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        });
    }

    /**
     * Cosine similarity for two equal-length numeric vectors. Returns 0
     * when either vector is empty / mismatched / zero-magnitude so the
     * caller can blend safely.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        $len = min(count($a), count($b));
        if ($len === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;
        for ($i = 0; $i < $len; $i++) {
            $x = (float) $a[$i];
            $y = (float) $b[$i];
            $dot += $x * $y;
            $magA += $x * $x;
            $magB += $y * $y;
        }
        if ($magA <= 0.0 || $magB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($magA) * sqrt($magB));
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

    public function syncProducts(string $shopDomain, ?int $limit = null): int
    {
        $shopDomain = trim($shopDomain);
        if ($shopDomain === '') {
            return 0;
        }

        $pageSize = (int) config('sales.knowledge.products.page_size', 50);
        $maxPages = (int) config('sales.knowledge.products.max_pages', 10);
        $connection = (string) config('sales.queue.connection', 'redis');
        $queue = (string) config('sales.queue.sync', 'sync');
        $cap = $limit !== null && $limit > 0 ? $limit : PHP_INT_MAX;

        $dispatched = 0;
        $cursor = null;

        for ($page = 0; $page < $maxPages; $page++) {
            try {
                $response = $this->storefront()->query('storefront/products/get_all_products', [
                    'limit' => min($pageSize, max(1, $cap - $dispatched)),
                    'after' => $cursor,
                    'sortKey' => 'TITLE',
                    'reverse' => false,
                    'query' => null,
                    'country' => null,
                ]);
            } catch (Throwable $e) {
                $this->logWarning('Knowledge sync: products page failed', [
                    'shop' => $shopDomain,
                    'page' => $page,
                    'error' => $e->getMessage(),
                ], 'ai');
                break;
            }

            $productsConn = $response['data']['products'] ?? [];
            $edges = $productsConn['edges'] ?? [];
            if (! is_array($edges) || $edges === []) {
                break;
            }

            foreach ($edges as $edge) {
                if ($dispatched >= $cap) {
                    break 2;
                }
                $node = $edge['node'] ?? null;
                if (! is_array($node)) {
                    continue;
                }

                $title = (string) ($node['title'] ?? 'Untitled product');
                $handle = (string) ($node['handle'] ?? Str::slug($title));
                if ($handle === '') {
                    $handle = 'product-'.substr(md5($title), 0, 8);
                }

                $rawContent = $this->composeProductRawContent($node);

                SummariseKnowledgeItemJob::dispatch(
                    $shopDomain,
                    StoreKnowledge::TYPE_PRODUCT,
                    $title,
                    $handle,
                    $rawContent,
                    isset($node['updatedAt']) ? (string) $node['updatedAt'] : null,
                )->onConnection($connection)->onQueue($queue);

                $dispatched++;
            }

            $pageInfo = $productsConn['pageInfo'] ?? [];
            if (empty($pageInfo['hasNextPage'])) {
                break;
            }
            $cursor = (string) ($pageInfo['endCursor'] ?? '');
            if ($cursor === '') {
                break;
            }
        }

        return $dispatched;
    }

    public function syncUrls(string $shopDomain, array $urls): int
    {
        $shopDomain = trim($shopDomain);
        if ($shopDomain === '' || $urls === []) {
            return 0;
        }

        $urls = $this->normaliseUrlList($urls);
        if ($urls === []) {
            return 0;
        }

        $concurrency = max(1, (int) config('sales.knowledge.urls.concurrency', 4));
        $timeout = max(1, (int) config('sales.knowledge.urls.fetch_timeout', 15));
        $userAgent = (string) config('sales.knowledge.urls.user_agent', 'ScottStonebridgeBot/1.0');
        $connection = (string) config('sales.queue.connection', 'redis');
        $queue = (string) config('sales.queue.sync', 'sync');

        $dispatched = 0;

        // Process URLs in chunks so Http::pool stays bounded and Shopify
        // storefronts aren't hit harder than $concurrency at once.
        foreach (array_chunk($urls, $concurrency) as $batch) {
            $responses = Http::pool(function ($pool) use ($batch, $timeout, $userAgent) {
                $calls = [];
                foreach ($batch as $url) {
                    $calls[] = $pool
                        ->withHeaders(['User-Agent' => $userAgent, 'Accept' => 'text/html'])
                        ->timeout($timeout)
                        ->retry(2, 500, throw: false)
                        ->get($url);
                }

                return $calls;
            });

            foreach ($batch as $i => $url) {
                $response = $responses[$i] ?? null;
                if ($response === null || ! method_exists($response, 'successful') || ! $response->successful()) {
                    Log::channel('ai')->info('knowledge:sync-urls: skip', [
                        'shop' => $shopDomain,
                        'url' => $url,
                        'status' => $response && method_exists($response, 'status') ? $response->status() : null,
                    ]);

                    continue;
                }

                $html = (string) $response->body();
                if ($html === '') {
                    continue;
                }

                [$title, $text] = $this->extractTextFromHtml($html, $url);
                if ($text === '') {
                    continue;
                }

                SummariseKnowledgeItemJob::dispatch(
                    $shopDomain,
                    StoreKnowledge::TYPE_URL,
                    $title,
                    'url-'.md5($url),
                    "URL: {$url}\n\n{$text}",
                    null,
                )->onConnection($connection)->onQueue($queue);

                $dispatched++;
            }
        }

        return $dispatched;
    }

    public function discoverSitemapUrls(string $shopDomain): array
    {
        $shopDomain = trim($shopDomain);
        if ($shopDomain === '') {
            return [];
        }

        $max = max(1, (int) config('sales.knowledge.urls.sitemap_max_urls', 200));
        $timeout = max(1, (int) config('sales.knowledge.urls.fetch_timeout', 15));
        $userAgent = (string) config('sales.knowledge.urls.user_agent', 'ScottStonebridgeBot/1.0');
        $rootUrl = 'https://'.$shopDomain.'/sitemap.xml';

        $collected = [];
        $queue = [$rootUrl];
        $seenSitemaps = [];

        while ($queue !== [] && count($collected) < $max) {
            $sitemapUrl = array_shift($queue);
            if (isset($seenSitemaps[$sitemapUrl])) {
                continue;
            }
            $seenSitemaps[$sitemapUrl] = true;

            try {
                $response = Http::withHeaders(['User-Agent' => $userAgent])
                    ->timeout($timeout)
                    ->retry(2, 500, throw: false)
                    ->get($sitemapUrl);
            } catch (Throwable $e) {
                $this->logWarning('Knowledge sync: sitemap fetch failed', [
                    'shop' => $shopDomain,
                    'sitemap' => $sitemapUrl,
                    'error' => $e->getMessage(),
                ], 'ai');

                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            try {
                $xml = new SimpleXMLElement($response->body());
            } catch (Throwable $e) {
                Log::channel('ai')->info('knowledge:sync-urls: invalid sitemap', [
                    'shop' => $shopDomain,
                    'sitemap' => $sitemapUrl,
                ]);

                continue;
            }

            $name = $xml->getName();
            if ($name === 'sitemapindex') {
                foreach ($xml->sitemap as $entry) {
                    $loc = trim((string) $entry->loc);
                    if ($loc !== '') {
                        $queue[] = $loc;
                    }
                }

                continue;
            }

            // <urlset>
            foreach ($xml->url as $entry) {
                $loc = trim((string) $entry->loc);
                if ($loc === '') {
                    continue;
                }
                $collected[$loc] = true;
                if (count($collected) >= $max) {
                    break;
                }
            }
        }

        return array_keys($collected);
    }

    /**
     * Build the raw content string that gets summarised for a product row.
     * Combines descriptionHtml (stripped) with structured fields so the
     * summariser has enough signal to write a useful one-liner.
     *
     * @param  array<string, mixed>  $node
     */
    private function composeProductRawContent(array $node): string
    {
        $description = trim((string) ($node['description'] ?? ''));
        if ($description === '') {
            $description = trim(strip_tags((string) ($node['descriptionHtml'] ?? '')));
        }

        $vendor = trim((string) ($node['vendor'] ?? ''));
        $type = trim((string) ($node['productType'] ?? ''));
        $tags = $node['tags'] ?? [];
        if (! is_array($tags)) {
            $tags = [];
        }
        $tagsLine = implode(', ', array_map('strval', $tags));

        $variantTitles = [];
        foreach (($node['variants']['edges'] ?? []) as $edge) {
            $vNode = $edge['node'] ?? [];
            if (! is_array($vNode)) {
                continue;
            }
            $vTitle = (string) ($vNode['title'] ?? '');
            if ($vTitle === '' || $vTitle === 'Default Title') {
                continue;
            }
            $variantTitles[] = $vTitle;
            if (count($variantTitles) >= 20) {
                break;
            }
        }
        $variantsLine = implode(', ', $variantTitles);

        $url = (string) ($node['onlineStoreUrl'] ?? '');

        $parts = [$description];
        if ($vendor !== '') {
            $parts[] = "Vendor: {$vendor}";
        }
        if ($type !== '') {
            $parts[] = "Type: {$type}";
        }
        if ($tagsLine !== '') {
            $parts[] = "Tags: {$tagsLine}";
        }
        if ($variantsLine !== '') {
            $parts[] = "Variants: {$variantsLine}";
        }
        if ($url !== '') {
            $parts[] = "URL: {$url}";
        }

        return implode("\n\n", array_filter($parts, static fn ($s) => trim((string) $s) !== ''));
    }

    /**
     * Normalise a user-supplied URL list — trims, drops empties, dedupes,
     * and keeps only http(s) URLs. Defensive: anything else is silently
     * dropped so a single bad URL can't poison the whole batch.
     *
     * @param  list<string>  $urls
     * @return list<string>
     */
    private function normaliseUrlList(array $urls): array
    {
        $out = [];
        foreach ($urls as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            if (! preg_match('#^https?://#i', $url)) {
                continue;
            }
            $out[$url] = true;
        }

        return array_keys($out);
    }

    /**
     * Extract a readable title + body text from an HTML response. Removes
     * script/style/nav/footer noise via DomCrawler so the summariser only
     * sees content the human visitor would read.
     *
     * @return array{0:string, 1:string} [title, text]
     */
    private function extractTextFromHtml(string $html, string $url): array
    {
        try {
            $crawler = new Crawler($html);
        } catch (Throwable $e) {
            return [$this->fallbackTitleFromUrl($url), ''];
        }

        $title = $this->fallbackTitleFromUrl($url);
        try {
            $titleNode = $crawler->filter('title');
            if ($titleNode->count() > 0) {
                $candidate = trim($titleNode->first()->text(''));
                if ($candidate !== '') {
                    $title = mb_substr($candidate, 0, 200);
                }
            }
        } catch (Throwable) {
            // ignore — fall back to URL-derived title
        }

        // Drop only the high-noise nodes. Aggressive stripping (noscript,
        // nav, header, footer) wipes the entire body on some Shopify
        // themes that wrap the main content inside a CSS-toggled
        // <noscript>-adjacent block, so we keep the selector list
        // intentionally narrow and let the summariser cope with a
        // small amount of navigation chrome.
        foreach (['script', 'style', 'iframe', 'svg'] as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $node): void {
                    foreach ($node as $domNode) {
                        if ($domNode->parentNode !== null) {
                            $domNode->parentNode->removeChild($domNode);
                        }
                    }
                });
            } catch (Throwable) {
                // ignore — DOM mutation is best-effort
            }
        }

        // Prefer <main> or <article>; fall back to <body>.
        $text = '';
        foreach (['main', 'article', 'body'] as $selector) {
            try {
                $node = $crawler->filter($selector);
                if ($node->count() > 0) {
                    $text = trim($node->first()->text(''));
                    if ($text !== '') {
                        break;
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }

        // Collapse runs of whitespace so the summariser doesn't waste
        // tokens on indentation noise.
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return [$title, $text];
    }

    private function fallbackTitleFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $segments = array_values(array_filter(explode('/', $path), static fn ($s) => $s !== ''));
        $last = end($segments);
        if ($last === false || $last === '') {
            return $url;
        }

        return mb_substr(Str::title(str_replace(['-', '_'], ' ', (string) $last)), 0, 200);
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
