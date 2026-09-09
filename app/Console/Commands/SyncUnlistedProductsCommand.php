<?php

namespace App\Console\Commands;

use App\Services\CampaignEmail\UnlistedProductSyncService;
use Illuminate\Console\Command;

class SyncUnlistedProductsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:sync-unlisted-products';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync Shopify unlisted products into the local catalog used by the campaign product picker';

    /**
     * Execute the console command.
     */
    public function handle(UnlistedProductSyncService $sync): int
    {
        $result = $sync->sync();

        $this->info("Synced {$result['synced']} unlisted products ({$result['deactivated']} deactivated).");

        return self::SUCCESS;
    }
}
