<?php

namespace App\Console\Commands;

use App\Models\ShopifyWebhookEvent;
use App\Services\Shopify\Webhooks\WebhookInbox;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RetryShopifyWebhookEventsCommand extends Command
{
    protected $signature = 'webhooks:retry
        {--failed : Also replay events that ended in the failed state}
        {--id=* : Replay specific event ids (any non-finished or failed state)}';

    protected $description = 'Re-queue stale (received/processing) webhook events, and optionally replay failed ones';

    public function handle(WebhookInbox $inbox): int
    {
        $staleBefore = Carbon::now()->subMinutes(max(1, (int) config('shopify.webhook_inbox.stale_after_minutes', 10)));
        $ids = array_filter(array_map('intval', (array) $this->option('id')));

        $query = ShopifyWebhookEvent::query()->where(function ($group) use ($staleBefore, $ids) {
            $group->where(function ($stale) use ($staleBefore) {
                $stale->whereIn('status', [ShopifyWebhookEvent::STATUS_RECEIVED, ShopifyWebhookEvent::STATUS_PROCESSING])
                    ->where('updated_at', '<=', $staleBefore);
            });

            if ($this->option('failed')) {
                $group->orWhere('status', ShopifyWebhookEvent::STATUS_FAILED);
            }

            if ($ids !== []) {
                $group->orWhere(function ($specific) use ($ids) {
                    $specific->whereIn('id', $ids)->whereNotIn('status', [
                        ShopifyWebhookEvent::STATUS_PROCESSED,
                        ShopifyWebhookEvent::STATUS_IGNORED,
                    ]);
                });
            }
        });

        $requeued = 0;

        $query->orderBy('id')->each(function (ShopifyWebhookEvent $event) use ($inbox, &$requeued) {
            // Reset to a claimable state, then queue processing again.
            ShopifyWebhookEvent::query()->whereKey($event->id)->update([
                'status' => ShopifyWebhookEvent::STATUS_RECEIVED,
            ]);

            $inbox->dispatch($event);
            $requeued++;
        });

        $this->info("Re-queued {$requeued} webhook events.");

        return self::SUCCESS;
    }
}
