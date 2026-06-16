<?php

namespace App\Jobs\EmailReading;

use App\Mail\EmailReadingMail;
use App\Models\EmailReadingDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendEmailReadingJob implements ShouldQueue
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
        /** @var EmailReadingDelivery|null $delivery */
        $delivery = EmailReadingDelivery::with('product')->find($this->deliveryId);

        if (! $delivery) {
            return;
        }

        if ($delivery->status === EmailReadingDelivery::STATUS_SENT) {
            return;
        }

        if ($delivery->status !== EmailReadingDelivery::STATUS_GENERATED) {
            Log::channel('shopify_webhooks')->warning('SendEmailReadingJob skipped non-generated delivery', [
                'delivery_id' => $delivery->id,
                'status' => $delivery->status,
            ]);

            return;
        }

        Mail::to($delivery->customer_email)->send(new EmailReadingMail($delivery));

        $delivery->forceFill([
            'status' => EmailReadingDelivery::STATUS_SENT,
            'sent_at' => Carbon::now(),
        ])->save();

        Log::channel('shopify_webhooks')->info('Reading email sent', [
            'delivery_id' => $delivery->id,
            'to' => $delivery->customer_email,
        ]);
    }

    public function failed(Throwable $e): void
    {
        $delivery = EmailReadingDelivery::find($this->deliveryId);
        if (! $delivery) {
            return;
        }

        $delivery->markFailed('Send failed: '.$e->getMessage());

        NotifyReadingFailureJob::dispatch($delivery->id, 'Send failed: '.$e->getMessage())
            ->onConnection(config('email_reading.queue.connection'))
            ->onQueue(config('email_reading.queue.mail'));
    }
}
