<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\Services\Sales\StoreKnowledgeServiceInterface;
use App\Models\StoreKnowledge;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Pull products from the shop's Shopify Storefront GraphQL and stash one
 * AI-summarised row per product into `store_knowledge`. Distinct from
 * `knowledge:sync` (which only handles Pages/Blogs/Policies) so operators
 * can run the catalogue sync on its own cadence.
 *
 * Examples:
 *   php artisan knowledge:sync-products scott-stonebridge.myshopify.com --now
 *   php artisan knowledge:sync-products scott-stonebridge.myshopify.com --now --limit=20
 */
class SyncProductKnowledgeCommand extends Command
{
    protected $signature = 'knowledge:sync-products
        {shop : Shop domain, e.g. scott-stonebridge.myshopify.com}
        {--limit= : Cap the number of products synced (default: all)}
        {--now : Run summariser jobs inline (forces sync queue connection)}';

    protected $description = 'Sync Shopify products into the store_knowledge table for AI prompt injection.';

    public function handle(StoreKnowledgeServiceInterface $knowledge): int
    {
        $shop = trim((string) $this->argument('shop'));
        if ($shop === '') {
            $this->error('Shop domain is required.');

            return self::FAILURE;
        }

        $limit = $this->option('limit');
        $limit = $limit !== null && $limit !== '' ? (int) $limit : null;
        if ($limit !== null && $limit <= 0) {
            $this->error('--limit must be a positive integer.');

            return self::FAILURE;
        }

        $runNow = (bool) $this->option('now');
        $originalConnection = Config::get('sales.queue.connection');

        if ($runNow) {
            // Force in-process execution of each SummariseKnowledgeItemJob
            // for this CLI run only. Restored in `finally` so other queues
            // and the rest of the app are unaffected.
            Config::set('sales.queue.connection', 'sync');
        }

        try {
            $this->info("Syncing products for {$shop}…");
            $dispatched = $knowledge->syncProducts($shop, $limit);

            if ($runNow) {
                $count = StoreKnowledge::query()
                    ->where('shop_domain', $shop)
                    ->where('content_type', StoreKnowledge::TYPE_PRODUCT)
                    ->count();
                $this->info("Dispatched {$dispatched} product summary jobs. Total product rows for {$shop}: {$count}.");
            } else {
                $this->info("Dispatched {$dispatched} product summary jobs. Run `php artisan queue:work --queue=sync` to process them.");
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Product sync failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            if ($runNow) {
                Config::set('sales.queue.connection', $originalConnection);
            }
        }
    }
}
