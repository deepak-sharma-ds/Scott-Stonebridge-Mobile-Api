<?php

namespace App\Jobs\Campaign;

use App\Mail\CampaignFailureAdminMail;
use App\Models\CampaignDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotifyCampaignFailureJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $deliveryId, public string $reason) {}

    public function handle(): void
    {
        $admin = (string) config('campaign_email.admin_notify_email');
        if ($admin === '') {
            Log::channel('shopify_webhooks')->warning('Campaign admin notify email not configured; skipping notification', [
                'delivery_id' => $this->deliveryId,
            ]);

            return;
        }

        /** @var CampaignDelivery|null $delivery */
        $delivery = CampaignDelivery::find($this->deliveryId);
        if (! $delivery) {
            return;
        }

        Mail::to($admin)->send(new CampaignFailureAdminMail($delivery, $this->reason));
    }
}
