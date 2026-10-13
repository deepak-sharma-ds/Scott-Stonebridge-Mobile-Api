<?php

namespace App\Jobs\Shopify;

use App\Contracts\Shopify\ShopifyWebhookHandlerInterface;
use App\Models\ShopifyWebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes one stored webhook event through its endpoint's handler and
 * records the outcome on the event row. A thrown exception releases the event
 * for a retry (with backoff); after the final attempt it is marked `failed`
 * and can be replayed from the admin page or `webhooks:retry`.
 */
class ProcessShopifyWebhookEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public int $eventId) {}

    public function backoff(): array
    {
        return [30, 120, 300, 900, 1800];
    }

    public function handle(): void
    {
        // Atomic claim: only one worker processes an event at a time, and
        // finished events are never reprocessed.
        $claimed = ShopifyWebhookEvent::query()
            ->whereKey($this->eventId)
            ->whereIn('status', [ShopifyWebhookEvent::STATUS_RECEIVED, ShopifyWebhookEvent::STATUS_FAILED])
            ->update([
                'status' => ShopifyWebhookEvent::STATUS_PROCESSING,
                'attempts' => DB::raw('attempts + 1'),
            ]);

        if ($claimed !== 1) {
            return;
        }

        $event = ShopifyWebhookEvent::query()->findOrFail($this->eventId);

        // Endpoint names contain a dot, so index the array directly rather than
        // using dot-notation config().
        $handlerClass = (array) config('shopify.webhook_inbox.handlers', []);
        $handlerClass = $handlerClass[$event->endpoint] ?? null;

        if (! $handlerClass) {
            $this->markFinished($event, ShopifyWebhookEvent::STATUS_FAILED, null, "No handler registered for endpoint [{$event->endpoint}].");

            return;
        }

        try {
            /** @var ShopifyWebhookHandlerInterface $handler */
            $handler = app($handlerClass);
            $outcome = $handler->handle($event);
        } catch (Throwable $e) {
            // Release for the queue's next attempt.
            ShopifyWebhookEvent::query()->whereKey($event->id)->update([
                'status' => ShopifyWebhookEvent::STATUS_RECEIVED,
                'error' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            throw $e;
        }

        $this->markFinished($event, $outcome->status, $outcome->reason, null);
    }

    public function failed(Throwable $e): void
    {
        Log::channel('shopify_webhooks')->error('Webhook event processing failed', [
            'event_id' => $this->eventId,
            'error' => $e->getMessage(),
        ]);

        ShopifyWebhookEvent::query()->whereKey($this->eventId)->update([
            'status' => ShopifyWebhookEvent::STATUS_FAILED,
            'error' => mb_substr($e->getMessage(), 0, 2000),
            'processed_at' => Carbon::now(),
        ]);
    }

    private function markFinished(ShopifyWebhookEvent $event, string $status, ?string $reason, ?string $error): void
    {
        ShopifyWebhookEvent::query()->whereKey($event->id)->update([
            'status' => $status,
            'reason' => $reason,
            'error' => $error,
            'processed_at' => Carbon::now(),
        ]);
    }
}
