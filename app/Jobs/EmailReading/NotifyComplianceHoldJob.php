<?php

namespace App\Jobs\EmailReading;

use App\Mail\ComplianceHoldAdminMail;
use App\Models\EmailReadingDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Alerts an administrator that a reading was quarantined by the Compliance
 * Gate and needs human review.
 */
class NotifyComplianceHoldJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $deliveryId) {}

    public function handle(): void
    {
        $admin = (string) config('email_reading.admin_notify_email');
        if ($admin === '') {
            Log::channel('shopify_webhooks')->warning('Admin notify email not configured; skipping compliance hold alert', [
                'delivery_id' => $this->deliveryId,
            ]);

            return;
        }

        /** @var EmailReadingDelivery|null $delivery */
        $delivery = EmailReadingDelivery::with('product')->find($this->deliveryId);
        if (! $delivery || $delivery->status !== EmailReadingDelivery::STATUS_COMPLIANCE_HOLD) {
            return;
        }

        Mail::to($admin)->send(new ComplianceHoldAdminMail($delivery));
    }
}
