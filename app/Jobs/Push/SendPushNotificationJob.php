<?php

namespace App\Jobs\Push;

use App\Contracts\Services\PushNotificationServiceInterface;
use App\Exceptions\PushTokenInvalidException;
use App\Models\PushNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one PushNotification row via FCM. A dead token (UNREGISTERED /
 * INVALID_ARGUMENT) revokes the device and does NOT retry; transient FCM
 * errors bubble up so the queue retries with backoff.
 */
class SendPushNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public int $pushNotificationId) {}

    public function backoff(): array
    {
        return [30, 60, 300, 900, 1800];
    }

    public function handle(PushNotificationServiceInterface $pushService): void
    {
        /** @var PushNotification|null $notification */
        $notification = PushNotification::with('deviceToken')->find($this->pushNotificationId);

        if (! $notification || $notification->status === PushNotification::STATUS_SENT) {
            return;
        }

        try {
            $messageId = $pushService->send($notification);
            $notification->markSent($messageId);

            Log::channel('push')->info('Push notification sent', [
                'push_notification_id' => $notification->id,
                'source_type' => $notification->source_type,
                'source_id' => $notification->source_id,
            ]);
        } catch (PushTokenInvalidException $e) {
            // Dead token: revoke it and stop — retrying would only fail again.
            $notification->deviceToken?->revoke();
            $notification->markFailed('token_invalid', $e->getMessage());

            Log::channel('push')->warning('Push token invalid, device revoked', [
                'push_notification_id' => $notification->id,
                'device_token_id' => $notification->device_token_id,
            ]);
        }
    }

    public function failed(Throwable $e): void
    {
        $notification = PushNotification::find($this->pushNotificationId);
        if (! $notification || $notification->status === PushNotification::STATUS_SENT) {
            return;
        }

        $notification->markFailed('send_failed', $e->getMessage());
    }
}
