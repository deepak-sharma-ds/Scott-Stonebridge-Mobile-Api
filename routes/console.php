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
| AI Sales Agent — Knowledge Sync (Phase 2 / Phase D)
|--------------------------------------------------------------------------
|
| Guarded by KNOWLEDGE_SYNC_SCHEDULE_ENABLED (ADR 0015 / Option B: Manual Admin
| Sync Only). By default, knowledge updates run manually on demand via
| `php artisan knowledge:sync {shop} --now`. Flip the env var to true if daily
| cron automation is needed.
|
*/
if (filter_var(env('KNOWLEDGE_SYNC_SCHEDULE_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
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
                ->onConnection((string) (config('sales.queue.connection') ?: config('queue.default', 'database')))
                ->onQueue((string) config('sales.queue.sync', 'sync'));
        }
    })->dailyAt(sprintf('%02d:00', (int) env('KNOWLEDGE_SYNC_HOUR', 2)))->name('ai-knowledge-sync');
}

/*
|--------------------------------------------------------------------------
| Marketing Push — Klaviyo campaign sweep
|--------------------------------------------------------------------------
|
| Klaviyo has no campaign-sent webhook on the standard plan, so this polls
| the Campaigns API every ten minutes for newly SENT campaigns and fans out
| per-recipient pushes. Guarded by push.enabled + push.sweep.enabled inside
| the command; withoutOverlapping prevents a slow run from stacking.
|
*/
Schedule::command('push:sweep-klaviyo-campaigns')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->name('push-sweep-klaviyo-campaigns');

/*
|--------------------------------------------------------------------------
| Campaign Product Picker — Unlisted Products sync
|--------------------------------------------------------------------------
|
| Keeps the local `unlisted_products` catalog in step with Shopify so the
| "Link a product" picker on a campaign's show page never has to call
| Shopify live.
|
*/
Schedule::command('shopify:sync-unlisted-products')
    ->hourly()
    ->withoutOverlapping()
    ->name('sync-unlisted-products');

/*
|--------------------------------------------------------------------------
| Email Reading + Campaign Email — due-delivery dispatchers
|--------------------------------------------------------------------------
|
| The delivery row (status + scheduled_at) is the single source of truth for
| when an email goes out. Every minute each dispatcher atomically claims due
| deliveries and queues an immediate send job, and recovers deliveries stuck
| in `sending`. Requires `php artisan schedule:run` in cron in production.
| The health check alerts when due deliveries are not being picked up.
|
*/
Schedule::command('readings:dispatch-due')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->name('readings-dispatch-due');

Schedule::command('campaign-emails:dispatch-due')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->name('campaign-emails-dispatch-due');

Schedule::command('email-automation:health')
    ->everyFiveMinutes()
    ->name('email-automation-health');

Schedule::command('webhooks:retry')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('webhooks-retry-stale');

/*
|--------------------------------------------------------------------------
| Bi-Directional Google Calendar Sync & Watch Channel Maintenance
|--------------------------------------------------------------------------
|
| calendar:renew-watch verifies and renews 7-day Google push webhook subscriptions daily.
| calendar:sync-delta runs incremental delta sync every 30 minutes as a safety net.
|
*/
Schedule::command('calendar:renew-watch')
    ->daily()
    ->withoutOverlapping()
    ->name('calendar-renew-watch');

Schedule::command('calendar:sync-delta')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->name('calendar-sync-delta');
