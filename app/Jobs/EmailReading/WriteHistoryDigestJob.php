<?php

namespace App\Jobs\EmailReading;

use App\Models\EmailReadingDelivery;
use App\Services\EmailReading\HistoryDigestService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the History Digest for a sent delivery.
 *
 * Dispatched after the email is accepted (or the delivery is marked sent
 * manually). It is best-effort: a permanent failure only logs a warning and
 * leaves the digest empty. It never changes the delivery's status, blocks
 * fulfilment, or raises an admin failure alert.
 */
class WriteHistoryDigestJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(public int $deliveryId) {}

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(HistoryDigestService $digests): void
    {
        /** @var EmailReadingDelivery|null $delivery */
        $delivery = EmailReadingDelivery::with('product')->find($this->deliveryId);

        if (! $delivery) {
            return;
        }

        if ($digests->write($delivery)) {
            Log::channel('shopify_webhooks')->info('History digest written', [
                'delivery_id' => $delivery->id,
            ]);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::channel('shopify_webhooks')->warning('History digest could not be written; the delivery contributes its answers only', [
            'delivery_id' => $this->deliveryId,
            'error' => $e->getMessage(),
        ]);
    }
}
