<?php

namespace App\Jobs\EmailReading;

use App\Mail\EmailReadingMail;
use App\Models\EmailReadingDelivery;
use App\Services\EmailReading\EmailReadingDeliveryLifecycle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one reading email for a delivery this job's claim token owns.
 *
 * Dispatched only by EmailReadingDeliveryLifecycle after it atomically moved
 * the delivery to `sending` — never delayed. The job re-validates the claim
 * on every run, so a stale or duplicate job (released claim, resend, legacy
 * delayed job from before the claim model) can never send a second email.
 */
class SendEmailReadingJob implements ShouldQueue
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

    public function handle(EmailReadingDeliveryLifecycle $lifecycle): void
    {
        if ($this->claimToken === null) {
            Log::channel('shopify_webhooks')->info('Legacy reading send job ignored; the dispatcher owns sending', [
                'delivery_id' => $this->deliveryId,
            ]);

            return;
        }

        /** @var EmailReadingDelivery|null $delivery */
        $delivery = EmailReadingDelivery::with('product')->find($this->deliveryId);

        if (! $delivery) {
            return;
        }

        if ($delivery->status !== EmailReadingDelivery::STATUS_SENDING || $delivery->claim_token !== $this->claimToken) {
            Log::channel('shopify_webhooks')->info('Stale reading send job ignored', [
                'delivery_id' => $delivery->id,
                'status' => $delivery->status,
            ]);

            return;
        }

        // sent_at is stamped right after Mail::send: a retry of a job whose
        // mail already left (crash before finalizing) skips straight to
        // finalizing instead of emailing the customer twice.
        if ($delivery->sent_at === null) {
            Mail::to($delivery->customer_email)->send(new EmailReadingMail($delivery));

            if (! $lifecycle->recordMailSent($delivery->id, $this->claimToken)) {
                return;
            }
        }

        if ($lifecycle->markSent($delivery->id, $this->claimToken)) {
            Log::channel('shopify_webhooks')->info('Reading email sent', [
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

        if (app(EmailReadingDeliveryLifecycle::class)->markSendFailed($this->deliveryId, $this->claimToken, $message)) {
            NotifyReadingFailureJob::dispatch($this->deliveryId, $message)
                ->onConnection(config('email_reading.queue.connection'))
                ->onQueue(config('email_reading.queue.mail'));
        }
    }
}
