<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\Services\Sales\StoreKnowledgeServiceInterface;
use App\Models\StoreKnowledge;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Fetch arbitrary URLs (or auto-discover via the shop's sitemap.xml),
 * scrape the rendered HTML to readable text, and store one AI-summarised
 * row per URL in `store_knowledge`. Useful for theme-rendered landing
 * pages, app-injected FAQ blocks, and any content not exposed by the
 * Admin API.
 *
 * Distinct from `knowledge:sync` (Admin Pages/Blogs/Policies) and
 * `knowledge:sync-products` (catalogue) so operators can run each on
 * its own cadence.
 *
 * Examples:
 *   php artisan knowledge:sync-urls scott-stonebridge.myshopify.com --sitemap --now
 *   php artisan knowledge:sync-urls scott-stonebridge.myshopify.com --url=https://scottstonebridge.com/pages/about --now
 *   php artisan knowledge:sync-urls scott-stonebridge.myshopify.com --sitemap --url=https://scottstonebridge.com/pages/promo
 */
class SyncUrlKnowledgeCommand extends Command
{
    protected $signature = 'knowledge:sync-urls
        {shop : Shop domain, e.g. scott-stonebridge.myshopify.com}
        {--sitemap : Auto-discover URLs from https://{shop}/sitemap.xml}
        {--url=* : Explicit URL to fetch (repeatable)}
        {--now : Run summariser jobs inline (forces sync queue connection)}';

    protected $description = 'Sync arbitrary URLs (or shop sitemap) into the store_knowledge table for AI prompt injection.';

    public function handle(StoreKnowledgeServiceInterface $knowledge): int
    {
        $shop = trim((string) $this->argument('shop'));
        if ($shop === '') {
            $this->error('Shop domain is required.');

            return self::FAILURE;
        }

        $urls = (array) $this->option('url');
        $useSitemap = (bool) $this->option('sitemap');

        if (! $useSitemap && $urls === []) {
            $this->error('Provide --sitemap and/or one or more --url= values.');

            return self::FAILURE;
        }

        if ($useSitemap) {
            $this->info("Discovering URLs from {$shop} sitemap…");
            $discovered = $knowledge->discoverSitemapUrls($shop);
            $this->info('Sitemap discovered '.count($discovered).' URL(s).');
            $urls = array_values(array_unique(array_merge($urls, $discovered)));
        }

        if ($urls === []) {
            $this->warn('No URLs to sync.');

            return self::SUCCESS;
        }

        $runNow = (bool) $this->option('now');
        $originalConnection = Config::get('sales.queue.connection');

        if ($runNow) {
            // Force in-process execution of each SummariseKnowledgeItemJob
            // for this CLI run only. Restored in `finally`.
            Config::set('sales.queue.connection', 'sync');
        }

        try {
            $this->info('Fetching '.count($urls).' URL(s) for '.$shop.'…');
            $dispatched = $knowledge->syncUrls($shop, $urls);

            if ($runNow) {
                $count = StoreKnowledge::query()
                    ->where('shop_domain', $shop)
                    ->where('content_type', StoreKnowledge::TYPE_URL)
                    ->count();
                $this->info("Dispatched {$dispatched} URL summary jobs. Total URL rows for {$shop}: {$count}.");
            } else {
                $this->info("Dispatched {$dispatched} URL summary jobs. Run `php artisan queue:work --queue=sync` to process them.");
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('URL sync failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            if ($runNow) {
                Config::set('sales.queue.connection', $originalConnection);
            }
        }
    }
}
