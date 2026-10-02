<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use App\Contracts\Shopify\AdminApiClientInterface;
use App\Jobs\Shopify\SyncCustomerMarketingConsentBatchJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Paginates every Shopify customer's email/SMS marketing consent via the
 * Admin GraphQL API and fans a queued job out per page, so a 50k+ customer
 * backfill doesn't block a single CLI process on ~thousands of sequential
 * Klaviyo calls. SyncCustomerMarketingConsentBatchJob does the actual
 * Klaviyo writes; ShopifyWebhookController handles the live per-event path.
 *
 * Supports:
 * - Delta sync: pass $since (or omit to reuse the last completed full run's
 *   watermark) so a recurring run only re-pulls customers Shopify has
 *   touched since then, instead of the entire customer base every time.
 * - Resume: if a run is interrupted mid-pagination, the next run can pick
 *   up from the last saved cursor instead of starting over.
 */
class MarketingConsentSyncService
{
    private const PAGE_SIZE = 250;

    private const MAX_PAGES = 1000; // supports up to 250k customers per run

    private const CURSOR_CACHE_KEY = 'shopify:marketing-consent-sync:cursor';

    private const LAST_SYNCED_CACHE_KEY = 'shopify:marketing-consent-sync:last-synced-at';

    public function __construct(
        private readonly AdminApiClientInterface $adminClient,
    ) {}

    /**
     * @return array{pages:int, customers:int, jobs_dispatched:int, query_filter:?string}
     */
    public function dispatch(?Carbon $since = null, bool $resume = false, bool $fresh = false): array
    {
        $cursor = $resume ? Cache::get(self::CURSOR_CACHE_KEY) : null;
        $queryFilter = $this->buildQueryFilter($since, $fresh);

        $page = 0;
        $customersSeen = 0;
        $jobsDispatched = 0;
        $hasNextPage = false;

        do {
            $variables = [
                'first' => self::PAGE_SIZE,
                'query' => $queryFilter,
            ];

            if ($cursor !== null) {
                $variables['after'] = $cursor;
            }

            $response = $this->adminClient->query('admin/customers/list_customers_marketing_consent', $variables);
            $customersData = $response['data']['customers'] ?? [];
            $edges = $customersData['edges'] ?? [];

            if ($edges !== []) {
                $nodes = array_map(fn (array $edge) => $edge['node'] ?? [], $edges);

                SyncCustomerMarketingConsentBatchJob::dispatch($nodes)
                    ->onQueue((string) config('shopify.queue.marketing_consent_sync', 'klaviyo-marketing-consent-sync'));

                $jobsDispatched++;
                $customersSeen += count($nodes);
            }

            $pageInfo = $customersData['pageInfo'] ?? [];
            $hasNextPage = (bool) ($pageInfo['hasNextPage'] ?? false);
            $cursor = $pageInfo['endCursor'] ?? null;

            if ($hasNextPage && $cursor !== null) {
                Cache::put(self::CURSOR_CACHE_KEY, $cursor, now()->addDay());
            }

            $page++;
        } while ($hasNextPage && $cursor !== null && $page < self::MAX_PAGES);

        if (! $hasNextPage) {
            Cache::forget(self::CURSOR_CACHE_KEY);
            Cache::forever(self::LAST_SYNCED_CACHE_KEY, now()->toIso8601String());
        }

        return [
            'pages' => $page,
            'customers' => $customersSeen,
            'jobs_dispatched' => $jobsDispatched,
            'query_filter' => $queryFilter,
        ];
    }

    private function buildQueryFilter(?Carbon $since, bool $fresh): ?string
    {
        if ($fresh) {
            return null;
        }

        $since ??= $this->lastSyncedAt();

        if ($since === null) {
            return null;
        }

        return "updated_at:>'{$since->clone()->utc()->format('Y-m-d\TH:i:s\Z')}'";
    }

    public function lastSyncedAt(): ?Carbon
    {
        $value = Cache::get(self::LAST_SYNCED_CACHE_KEY);

        return $value ? Carbon::parse($value) : null;
    }
}
