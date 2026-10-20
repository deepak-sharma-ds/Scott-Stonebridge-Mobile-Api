<?php

namespace App\Console\Commands;

use App\Models\Funnels\Funnel;
use App\Services\Funnels\FunnelSyncService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-creates the Shopify discount of every active funnel and republishes the storefront config.
 * Run once after switching the discount method (e.g. to the bundle Discount Function).
 */
class ResyncFunnels extends Command
{
    protected $signature = 'funnels:resync';

    protected $description = 'Re-create the Shopify discounts of all active bundle funnels (after a discount method change)';

    public function handle(FunnelSyncService $sync): int
    {
        $funnels = Funnel::with('items')->where('status', Funnel::ACTIVE)->orderBy('id')->get();
        if ($funnels->isEmpty()) {
            $this->info('No active funnels.');

            return self::SUCCESS;
        }

        $this->info('Discount method: ' . config('bundles.discount_mode', 'function'));
        $failed = 0;
        foreach ($funnels as $funnel) {
            try {
                $sync->sync($funnel, 'resync');
                $this->line("  ✓ #{$funnel->id} {$funnel->name}");
            } catch (Throwable $e) {
                $failed++;
                $this->error("  ✗ #{$funnel->id} {$funnel->name}: {$e->getMessage()}");
            }
        }

        $this->info(($funnels->count() - $failed) . ' of ' . $funnels->count() . ' funnel(s) re-synced.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
