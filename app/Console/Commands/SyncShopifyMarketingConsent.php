<?php

namespace App\Console\Commands;

use App\Services\Shopify\MarketingConsentSyncService;
use Illuminate\Console\Command;

class SyncShopifyMarketingConsent extends Command
{
    protected $signature = 'shopify:sync-marketing-consent
        {--fresh : Ignore the last-synced watermark and cursor, and re-pull every customer}
        {--resume : Continue from the last saved page cursor instead of starting over}';

    protected $description = 'Fan out queued jobs that sync Shopify customer marketing consent to Klaviyo';

    public function handle(MarketingConsentSyncService $sync): int
    {
        $result = $sync->dispatch(
            resume: (bool) $this->option('resume'),
            fresh: (bool) $this->option('fresh'),
        );

        $queue = (string) config('shopify.queue.marketing_consent_sync', 'klaviyo-marketing-consent-sync');

        $this->info(
            "Fetched {$result['pages']} page(s), {$result['customers']} customer(s), ".
            "dispatched {$result['jobs_dispatched']} job(s) to the '{$queue}' queue.".
            ($result['query_filter'] ? " Filter: {$result['query_filter']}" : ' Full resync.')
        );

        $this->comment("Run 'php artisan queue:work --queue={$queue}' if a worker isn't already processing it.");

        return self::SUCCESS;
    }
}
