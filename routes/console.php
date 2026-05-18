<?php

use App\Jobs\Sales\SyncStoreKnowledgeJob;
use App\Models\StoreKnowledge;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

/*
|--------------------------------------------------------------------------
| AI Sales Agent — Daily Knowledge Sync (Phase 2 / Phase D)
|--------------------------------------------------------------------------
|
| Walk every shop_domain known to the store_knowledge table (Phase 2 is
| single-shop in practice but the schedule scales when more shops land)
| and dispatch a SyncStoreKnowledgeJob. The hour is configurable via
| KNOWLEDGE_SYNC_HOUR. SHOPIFY_STORE_DOMAIN is included so the first
| sync still fires even before any rows exist.
|
*/
Schedule::call(function (): void {
    $hour = (int) config('sales.knowledge.sync_hour', 2);
    if ((int) now()->format('G') !== $hour) {
        return;
    }

    $shops = StoreKnowledge::query()
        ->select('shop_domain')
        ->distinct()
        ->pluck('shop_domain')
        ->all();

    $configured = (string) (config('shopify.store_domain') ?? '');
    if ($configured !== '' && ! in_array($configured, $shops, true)) {
        $shops[] = $configured;
    }

    foreach ($shops as $shop) {
        SyncStoreKnowledgeJob::dispatch((string) $shop)
            ->onConnection((string) config('sales.queue.connection', 'redis'))
            ->onQueue((string) config('sales.queue.sync', 'sync'));
    }
})->dailyAt(sprintf('%02d:00', (int) env('KNOWLEDGE_SYNC_HOUR', 2)))->name('ai-knowledge-sync');
