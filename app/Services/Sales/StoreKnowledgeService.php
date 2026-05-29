<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Contracts\Services\Sales\StoreKnowledgeServiceInterface;
use App\Contracts\Shopify\AdminApiClientInterface;
use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\Jobs\Sales\SummariseKnowledgeItemJob;
use App\Models\StoreKnowledge;
use App\Services\Base\BaseService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

        // Fallback to the `_default` bucket when none of the supplied
        // intents map to a content_type. Without this, unknown intents
        // (INTENT_UNKNOWN, INTENT_GREETING, classifier null returns)
        // would emit an empty STORE KNOWLEDGE block even with rows in
        // the table.
        if ($types === []) {
            foreach ((array) ($map['_default'] ?? []) as $type) {
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
            // Order by recency so freshly-synced types (e.g. products
            // and URLs just pulled in by knowledge:sync-products /
            // knowledge:sync-urls) reliably surface in the prompt
            // block, instead of being pushed past the char cap by
            // older page/policy rows.
            $rows = StoreKnowledge::query()
                ->forShop($shopDomain)
                ->forTypes(array_keys($types))
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
