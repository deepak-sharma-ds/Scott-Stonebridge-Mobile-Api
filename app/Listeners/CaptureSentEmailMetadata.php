<?php

namespace App\Listeners;

use App\Models\CampaignDelivery;
use App\Models\EmailReadingDelivery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records Delivery Proof for a customer email the moment the transport
 * accepts it: the RFC Message-ID and the mail server's acknowledgement
 * (Google's "250 2.0.0 OK" dialog). Only mailables tagged with
 * X-Delivery-Type / X-Delivery-Id are handled; system emails and unsaved
 * preview sends carry no tags and pass straight through.
 *
 * Auto-discovered from app/Listeners by its handle() type-hint.
 */
class CaptureSentEmailMetadata
{
    /** @var array<string,class-string<Model>> */
    private const DELIVERY_MODELS = [
        'reading' => EmailReadingDelivery::class,
        'campaign' => CampaignDelivery::class,
    ];

    public function handle(MessageSent $event): void
    {
        $headers = $event->sent->getOriginalMessage()->getHeaders();
        $type = $headers->get('X-Delivery-Type')?->getBodyAsString();
        $deliveryId = (int) $headers->get('X-Delivery-Id')?->getBodyAsString();

        $model = self::DELIVERY_MODELS[$type] ?? null;
        if ($model === null || $deliveryId <= 0) {
            return;
        }

        $messageId = $event->sent->getMessageId();
        $smtpResponse = $this->smtpResponse($event);

        DB::transaction(function () use ($model, $deliveryId, $messageId, $smtpResponse): void {
            $delivery = $model::query()->lockForUpdate()->find($deliveryId);
            if ($delivery === null) {
                return;
            }

            $history = (array) $delivery->send_history;
            if ($delivery->message_id !== null) {
                $history[] = [
                    'dispatched_at' => $delivery->dispatched_at?->format('Y-m-d H:i:s'),
                    'message_id' => $delivery->message_id,
                    'smtp_response' => $delivery->smtp_response,
                ];
            }

            $delivery->forceFill([
                'message_id' => $messageId,
                'smtp_response' => $smtpResponse,
                'dispatched_at' => Carbon::now(),
                'send_history' => $history === [] ? null : $history,
            ])->save();
        });
    }

    /**
     * The transport's SMTP dialog, or - for log/array mailers that never talk
     * SMTP - the name of the transport that accepted the message.
     */
    private function smtpResponse(MessageSent $event): string
    {
        $debug = trim($event->sent->getDebug());

        return $debug !== '' ? $debug : 'transport:'.config('mail.default');
    }
}
