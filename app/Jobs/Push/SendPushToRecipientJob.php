<?php

namespace App\Jobs\Push;

use App\Models\DeviceToken;
use App\Models\PushNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Fans a single marketing event (flow webhook or campaign recipient) out to
 * every active device the recipient has registered. Creates one idempotent
 * PushNotification row per device and queues the actual FCM send.
 */
class SendPushToRecipientJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public string $email,
        public string $sourceType,
        public string $sourceId,
        public string $messageId,
        public string $title,
        public string $body,
        public string $deepLink,
    ) {}

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(): void
    {
        if (! config('push.enabled', false)) {
            return;
        }

        $email = strtolower(trim($this->email));

        $devices = DeviceToken::active()->forEmail($email)->get();

        if ($devices->isEmpty()) {
            // Most campaign recipients have no app device; skip silently unless
            // observability is explicitly requested.
            if (config('push.log_skipped', false)) {
                PushNotification::firstOrCreate(
                    [
                        'source_type' => $this->sourceType,
                        'source_id' => $this->sourceId,
                        'recipient_email' => $email,
                        'device_token_id' => null,
                    ],
                    [
                        'message_id' => $this->messageId,
                        'title' => $this->title,
                        'body' => $this->body,
                        'data' => ['deep_link' => $this->deepLink],
                        'status' => PushNotification::STATUS_SKIPPED,
                    ]
                );
            }

            Log::channel('push')->debug('No active device for recipient', [
                'source_type' => $this->sourceType,
                'source_id' => $this->sourceId,
            ]);

            return;
        }

        foreach ($devices as $device) {
            $notification = PushNotification::firstOrCreate(
                [
                    'source_type' => $this->sourceType,
                    'source_id' => $this->sourceId,
                    'recipient_email' => $email,
                    'device_token_id' => $device->id,
                ],
                [
                    'message_id' => $this->messageId,
                    'title' => $this->title,
                    'body' => $this->body,
                    'data' => ['deep_link' => $this->deepLink],
                    'status' => PushNotification::STATUS_PENDING,
                ]
            );

            if ($notification->wasRecentlyCreated) {
                SendPushNotificationJob::dispatch($notification->id)
                    ->onConnection(config('push.queue.connection'))
                    ->onQueue(config('push.queue.default'));
            }
        }
    }
}
