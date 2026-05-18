<?php

declare(strict_types=1);

namespace App\Jobs\Sales;

use App\Contracts\Services\Sales\StoreKnowledgeServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Per-shop knowledge sync entry point. Fans out per-item summarisation
 * onto the same `sync` queue. Scheduled daily 02:00 (routes/console.php)
 * and dispatched immediately on chat start when no rows exist yet for a
 * shop (ChatbotService::startSession).
 *
 * Queue: `sync` on the Redis connection. Idempotent — the per-item job
 * upserts, so a duplicate run just refreshes summaries.
 */
class SyncStoreKnowledgeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var array<int, int> */
    public array $backoff = [300, 600, 1200];

    public function __construct(
        public readonly string $shopDomain,
    ) {}

    public function handle(StoreKnowledgeServiceInterface $knowledge): void
    {
        if ($this->shopDomain === '') {
            return;
        }

        $knowledge->syncAll($this->shopDomain);
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('error')->error('SyncStoreKnowledgeJob failed', [
            'shop' => $this->shopDomain,
            'error' => $exception->getMessage(),
        ]);
    }
}
