<?php

namespace App\Console\Commands;

use App\Services\EmailReading\EmailReadingDeliveryLifecycle;
use Illuminate\Console\Command;

class DispatchDueReadingDeliveriesCommand extends Command
{
    protected $signature = 'readings:dispatch-due';

    protected $description = 'Claim due email-reading deliveries and dispatch their send jobs; recover stuck sends';

    public function handle(EmailReadingDeliveryLifecycle $lifecycle): int
    {
        $recovered = $lifecycle->recoverStuck();
        $dispatched = $lifecycle->dispatchDue();

        $this->info("Dispatched {$dispatched} due readings (recovered: {$recovered['finalized']} finalized, {$recovered['released']} released, {$recovered['failed']} failed).");

        return self::SUCCESS;
    }
}
