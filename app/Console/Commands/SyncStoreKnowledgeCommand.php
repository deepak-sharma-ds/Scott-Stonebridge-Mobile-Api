<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\Services\Sales\StoreKnowledgeServiceInterface;
use App\Models\StoreKnowledge;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Throwable;

/**
 * Operator helper to feed knowledge into a shop's `store_knowledge` table.
 *
 * Three modes (additive — does NOT replace the scheduled
 * SyncStoreKnowledgeJob or POST /api/v1/ai/knowledge/faq endpoint):
 *
 *   php artisan knowledge:sync {shop}                           # pull Pages/Blogs/Policies from Shopify Admin
 *   php artisan knowledge:sync {shop} --faq --q="…" --a="…"     # upsert a single FAQ inline
 *   php artisan knowledge:sync {shop} --custom --title="…" --body="…" [--handle="…"]
 *
 * Sync mode dispatches per-item SummariseKnowledgeItemJob calls onto
 * the `sync` queue — start `php artisan queue:work --queue=sync` (or
 * pass --now to run them inline via dispatchSync).
 */
class SyncStoreKnowledgeCommand extends Command
{
    protected $signature = 'knowledge:sync
        {shop : Shop domain, e.g. scott-stonebridge.myshopify.com}
        {--faq : Upsert a single FAQ (requires --q and --a)}
        {--q= : FAQ question (faq mode)}
        {--a= : FAQ answer (faq mode)}
        {--custom : Upsert a single custom knowledge row (requires --title and --body)}
        {--title= : Title for custom row}
        {--body= : Raw content for custom row (will be summarised by gpt-4.1-mini)}
        {--handle= : Optional slug for custom row (default: slugified title)}
        {--now : In sync mode, force the `sync` queue connection so each summarisation job runs inline}';

    protected $description = 'Feed Page/Blog/Policy/FAQ/custom knowledge into store_knowledge for a shop.';

    public function handle(StoreKnowledgeServiceInterface $knowledge): int
    {
        $shop = trim((string) $this->argument('shop'));
        if ($shop === '') {
            $this->error('Shop domain is required.');

            return self::FAILURE;
        }

        if ($this->option('faq')) {
            return $this->handleFaq($knowledge, $shop);
        }

        if ($this->option('custom')) {
            return $this->handleCustom($shop);
        }

        return $this->handleSync($knowledge, $shop);
    }

    private function handleFaq(StoreKnowledgeServiceInterface $knowledge, string $shop): int
    {
        $q = (string) $this->option('q');
        $a = (string) $this->option('a');
        if ($q === '' || $a === '') {
            $this->error('FAQ mode requires --q="question" and --a="answer".');

            return self::FAILURE;
        }

        $knowledge->upsertFaq($shop, $q, $a);
        $this->info("FAQ upserted for {$shop}.");

        return self::SUCCESS;
    }

    private function handleCustom(string $shop): int
    {
        $title = trim((string) $this->option('title'));
        $body = trim((string) $this->option('body'));
        if ($title === '' || $body === '') {
            $this->error('Custom mode requires --title="…" and --body="…".');

            return self::FAILURE;
        }

        $handle = trim((string) $this->option('handle'));
        if ($handle === '') {
            $handle = Str::slug($title) ?: ('custom-'.Str::random(8));
        }

        StoreKnowledge::query()->updateOrCreate(
            [
                'shop_domain' => $shop,
                'content_type' => StoreKnowledge::TYPE_CUSTOM,
                'handle' => $handle,
            ],
            [
                'title' => $title,
                // Custom rows are author-provided; store body as summary so it
                // lands in the prompt verbatim without an OpenAI round-trip.
                'summary' => Str::limit($body, 1200, '…'),
                'raw_content' => $body,
                'last_synced_at' => Carbon::now(),
            ],
        );

        $this->info("Custom knowledge upserted for {$shop} (handle={$handle}).");

        return self::SUCCESS;
    }

    private function handleSync(StoreKnowledgeServiceInterface $knowledge, string $shop): int
    {
        $runNow = (bool) $this->option('now');
        $originalConnection = Config::get('sales.queue.connection');

        if ($runNow) {
            // Temporarily route knowledge jobs to the synchronous queue
            // driver so each SummariseKnowledgeItemJob runs in-process and
            // upserts before this command exits. Config swap is scoped to
            // this CLI run only — does not touch .env or affect other
            // queues/workers.
            Config::set('sales.queue.connection', 'sync');
        }

        try {
            $this->info("Syncing knowledge for {$shop}…");
            $knowledge->syncAll($shop);

            if ($runNow) {
                $count = StoreKnowledge::query()->where('shop_domain', $shop)->count();
                $this->info("Sync complete. Rows in store_knowledge for {$shop}: {$count}.");
            } else {
                $this->info('Sync dispatched. Run `php artisan queue:work --queue=sync` to process items, or rerun with --now.');
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Sync failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            if ($runNow) {
                Config::set('sales.queue.connection', $originalConnection);
            }
        }
    }
}
