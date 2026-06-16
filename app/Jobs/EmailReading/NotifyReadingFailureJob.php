<?php

namespace App\Jobs\EmailReading;

use App\Mail\ReadingFailureAdminMail;
use App\Models\EmailReadingDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotifyReadingFailureJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $deliveryId, public string $reason) {}

    public function handle(): void
    {
        $admin = (string) config('email_reading.admin_notify_email');
        if ($admin === '') {
            Log::channel('shopify_webhooks')->warning('Admin notify email not configured; skipping notification', [
                'delivery_id' => $this->deliveryId,
            ]);

            return;
        }

        /** @var EmailReadingDelivery|null $delivery */
        $delivery = EmailReadingDelivery::with('product')->find($this->deliveryId);
        if (! $delivery) {
            return;
        }

        Mail::to($admin)->send(new ReadingFailureAdminMail($delivery, $this->reason));
    }
}
