<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\Services\Sales\StoreKnowledgeServiceInterface;
use App\Models\StoreKnowledge;
use Illuminate\Console\Command;
use OpenAI\Laravel\Facades\OpenAI;
use Throwable;

/**
 * Batch-backfill OpenAI embeddings for store_knowledge rows that don't
 * have one yet (or all rows when --missing-only is omitted). The
 * embeddings API accepts batched inputs in a single call so this
 * minimises round-trips: default 50 rows per batch.
 *
 * Examples:
 *   php artisan knowledge:embed scott-stonebridge.myshopify.com --missing-only
 *   php artisan knowledge:embed scott-stonebridge.myshopify.com --limit=20
 */
class BackfillKnowledgeEmbeddingsCommand extends Command
{
    protected $signature = 'knowledge:embed
        {shop : Shop domain, e.g. scott-stonebridge.myshopify.com}
        {--limit= : Cap the number of rows processed (default: all)}
        {--missing-only : Only embed rows whose embedding is currently NULL}';

    protected $description = 'Backfill OpenAI embeddings for store_knowledge rows so semantic retrieval can rank by cosine similarity.';

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

        $missingOnly = (bool) $this->option('missing-only');
        $model = (string) config('sales.knowledge.embedding.model', 'text-embedding-3-small');
        $batchSize = max(1, (int) config('sales.knowledge.embedding.batch_size', 50));
        $sleepMs = max(0, (int) config('sales.knowledge.embedding.batch_sleep_ms', 0));

        $query = StoreKnowledge::query()->where('shop_domain', $shop);
        if ($missingOnly) {
            $query->whereNull('embedding');
        }

        $total = (clone $query)->count();
        if ($limit !== null) {
            $total = min($total, $limit);
        }

        if ($total === 0) {
            $this->info('Nothing to embed.');

            return self::SUCCESS;
        }

        $this->info("Embedding {$total} row(s) for {$shop} with {$model}…");

        $processed = 0;
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->orderBy('id')->chunkById($batchSize, function ($rows) use (&$processed, $total, $model, $sleepMs, $bar): bool {
            $inputs = [];
            $ids = [];
            $reachedLimit = false;
            foreach ($rows as $row) {
                if ($processed >= $total) {
                    $reachedLimit = true;
                    break;
                }
                $text = trim(((string) $row->title)."\n\n".((string) $row->summary));
                if ($text === '') {
                    $processed++;
                    $bar->advance();

                    continue;
                }
                $inputs[] = mb_strimwidth($text, 0, 8000, '…');
                $ids[] = $row->id;
                $processed++;
            }

            if ($inputs !== []) {
                try {
                    $response = OpenAI::embeddings()->create([
                        'model' => $model,
                        'input' => $inputs,
                    ]);

                    foreach ($response->embeddings as $i => $embedding) {
                        $rowId = $ids[$i] ?? null;
                        if ($rowId === null) {
                            continue;
                        }
                        $vector = $embedding->embedding ?? null;
                        if (! is_array($vector) || $vector === []) {
                            continue;
                        }
                        StoreKnowledge::query()->whereKey($rowId)->update([
                            'embedding' => array_map('floatval', $vector),
                            'embedding_model' => $model,
                            'embedded_at' => now(),
                        ]);
                    }
                } catch (Throwable $e) {
                    $this->warn("Batch failed: {$e->getMessage()}");
                }

                $bar->advance(count($inputs));
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }

            return ! $reachedLimit && $processed < $total;
        });

        $bar->finish();
        $this->newLine(2);

        $knowledge->invalidateCache($shop);
        $this->info("Backfill complete. Processed {$processed} row(s). Cache invalidated for {$shop}.");

        return self::SUCCESS;
    }
}
