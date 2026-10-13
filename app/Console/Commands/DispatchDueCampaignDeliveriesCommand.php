<?php

namespace App\Console\Commands;

use App\Services\CampaignEmail\CampaignDeliveryLifecycle;
use Illuminate\Console\Command;

class DispatchDueCampaignDeliveriesCommand extends Command
{
    protected $signature = 'campaign-emails:dispatch-due';

    protected $description = 'Claim due campaign deliveries and dispatch their send jobs; recover stuck sends';

    public function handle(CampaignDeliveryLifecycle $lifecycle): int
    {
        $recovered = $lifecycle->recoverStuck();
        $dispatched = $lifecycle->dispatchDue();

        $this->info("Dispatched {$dispatched} due campaign deliveries (recovered: {$recovered['finalized']} finalized, {$recovered['released']} released, {$recovered['failed']} failed).");

        return self::SUCCESS;
    }
}
