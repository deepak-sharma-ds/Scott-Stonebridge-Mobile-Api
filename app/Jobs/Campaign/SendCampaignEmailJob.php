<?php

namespace App\Jobs\Campaign;

use App\Mail\CampaignEmailMail;
use App\Models\CampaignDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendCampaignEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public int $deliveryId) {}

    public function backoff(): array
    {
        return [30, 120, 300, 900, 1800];
    }

    public function handle(): void
    {
        /** @var CampaignDelivery|null $delivery */
        $delivery = CampaignDelivery::with('campaignProduct.response')->find($this->deliveryId);

        if (! $delivery) {
            return;
        }

        if ($delivery->status === CampaignDelivery::STATUS_SENT) {
            return;
        }

        if ($delivery->status !== CampaignDelivery::STATUS_PENDING) {
            Log::channel('shopify_webhooks')->warning('SendCampaignEmailJob skipped non-pending delivery', [
                'delivery_id' => $delivery->id,
                'status' => $delivery->status,
            ]);

            return;
        }

        Mail::to($delivery->customer_email)->send(new CampaignEmailMail($delivery));

        $delivery->forceFill([
            'status' => CampaignDelivery::STATUS_SENT,
            'sent_at' => Carbon::now(),
        ])->save();

        Log::channel('shopify_webhooks')->info('Campaign email sent', [
            'delivery_id' => $delivery->id,
            'to' => $delivery->customer_email,
        ]);

        // Email delivered: fulfill the Shopify order line item in its own job
        // so a fulfillment failure never blocks or re-sends the email.
        if ((bool) config('campaign_email.fulfillment.enabled', true)) {
            MarkCampaignOrderFulfilledJob::dispatch($delivery->id)
                ->onConnection(config('campaign_email.queue.connection'))
                ->onQueue(config('campaign_email.queue.mail'));
        }
    }

    public function failed(Throwable $e): void
    {
        $delivery = CampaignDelivery::find($this->deliveryId);
        if (! $delivery) {
            return;
        }

        $delivery->markFailed('Send failed: '.$e->getMessage());

        NotifyCampaignFailureJob::dispatch($delivery->id, 'Send failed: '.$e->getMessage())
            ->onConnection(config('campaign_email.queue.connection'))
            ->onQueue(config('campaign_email.queue.mail'));
    }
}
