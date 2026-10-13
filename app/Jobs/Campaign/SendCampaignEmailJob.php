<?php

namespace App\Jobs\Campaign;

use App\Mail\CampaignEmailMail;
use App\Models\CampaignDelivery;
use App\Services\CampaignEmail\CampaignDeliveryLifecycle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one campaign email for a delivery this job's claim token owns.
 *
 * Dispatched only by CampaignDeliveryLifecycle after it atomically moved the
 * delivery to `sending` — never delayed. The job re-validates the claim on
 * every run, so a stale or duplicate job (released claim, resend, legacy
 * delayed job from before the claim model) can never send a second email.
 */
class SendCampaignEmailJob implements ShouldQueue
{
    use Queueable;

    // Total retry time (30s+2m+5m) stays well under dispatch.stuck_after_minutes.
    public int $tries = 3;

    public int $timeout = 60;

    /**
     * @param  string|null  $claimToken  null only for jobs serialized before the claim model existed
     */
    public function __construct(public int $deliveryId, public ?string $claimToken = null) {}

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(CampaignDeliveryLifecycle $lifecycle): void
    {
        if ($this->claimToken === null) {
            Log::channel('shopify_webhooks')->info('Legacy campaign send job ignored; the dispatcher owns sending', [
                'delivery_id' => $this->deliveryId,
            ]);

            return;
        }

        /** @var CampaignDelivery|null $delivery */
        $delivery = CampaignDelivery::with('campaignProduct.response')->find($this->deliveryId);

        if (! $delivery) {
            return;
        }

        if ($delivery->status !== CampaignDelivery::STATUS_SENDING || $delivery->claim_token !== $this->claimToken) {
            Log::channel('shopify_webhooks')->info('Stale campaign send job ignored', [
                'delivery_id' => $delivery->id,
                'status' => $delivery->status,
            ]);

            return;
        }

        if ($delivery->sent_at === null) {
            if (! $delivery->campaignProduct?->response) {
                $message = 'No campaign response available at send time.';

                if ($lifecycle->markSendFailed($delivery->id, $this->claimToken, $message)) {
                    $this->notify($message);
                }

                return;
            }

            // The live response text is read at send time (edits reach every
            // unsent delivery). sent_at is stamped right after Mail::send so a
            // retry of a job whose mail already left never emails twice.
            Mail::to($delivery->customer_email)->send(new CampaignEmailMail($delivery));

            if (! $lifecycle->recordMailSent($delivery->id, $this->claimToken)) {
                return;
            }
        }

        if ($lifecycle->markSent($delivery->id, $this->claimToken)) {
            Log::channel('shopify_webhooks')->info('Campaign email sent', [
                'delivery_id' => $delivery->id,
                'to' => $delivery->customer_email,
            ]);
        }
    }

    public function failed(Throwable $e): void
    {
        if ($this->claimToken === null) {
            return;
        }

        $message = 'Send failed: '.$e->getMessage();

        if (app(CampaignDeliveryLifecycle::class)->markSendFailed($this->deliveryId, $this->claimToken, $message)) {
            $this->notify($message);
        }
    }

    private function notify(string $message): void
    {
        NotifyCampaignFailureJob::dispatch($this->deliveryId, $message)
            ->onConnection(config('campaign_email.queue.connection'))
            ->onQueue(config('campaign_email.queue.mail'));
    }
}
