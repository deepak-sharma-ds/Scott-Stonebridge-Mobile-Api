<?php

namespace App\Services\Push;

use App\Contracts\Services\PushNotificationServiceInterface;
use App\Exceptions\PushTokenInvalidException;
use App\Models\PushNotification;
use App\Services\Base\BaseService;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\InvalidArgument;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\CloudMessage;

/**
 * Delivers marketing push notifications through Firebase Cloud Messaging
 * (HTTP v1). One PushNotification row targets exactly one device token.
 *
 * The `data` payload is the deep-link contract with the React Native app:
 * every value is a string (FCM requirement) and the app reads `data.deep_link`
 * on notification open, falling back to the home screen when absent.
 */
class PushNotificationService extends BaseService implements PushNotificationServiceInterface
{
    public function __construct(
        protected Messaging $messaging
    ) {
        parent::__construct();
    }

    public function send(PushNotification $notification): string
    {
        $token = $notification->deviceToken?->fcm_token;

        if (! $token) {
            throw new PushTokenInvalidException('Notification has no device token');
        }

        $message = CloudMessage::new()
            ->withNotification([
                'title' => $notification->title,
                'body' => $notification->body,
            ])
            ->withData($this->buildData($notification))
            ->withAndroidConfig([
                'priority' => 'high',
                'notification' => [
                    'channel_id' => 'marketing',
                ],
            ])
            ->withApnsConfig([
                'headers' => ['apns-priority' => '10'],
                'payload' => [
                    'aps' => [
                        'sound' => 'default',
                        'mutable-content' => 1,
                    ],
                ],
            ])
            ->toToken($token);

        try {
            $result = $this->messaging->send($message);
        } catch (NotFound|InvalidArgument $e) {
            // Dead token: caller must revoke it and must NOT retry.
            throw new PushTokenInvalidException($e->getMessage(), 0, $e);
        }

        return (string) ($result['name'] ?? '');
    }

    /**
     * Build the string-only data payload consumed by the mobile app.
     *
     * @return array<string, string>
     */
    protected function buildData(PushNotification $notification): array
    {
        $stored = (array) ($notification->data ?? []);

        $data = [
            'type' => 'marketing_email',
            'source' => (string) $notification->source_type,
            'source_id' => (string) $notification->source_id,
            'deep_link' => (string) ($stored['deep_link'] ?? config('push.defaults.deep_link')),
            'correlation_id' => $this->getCorrelationId(),
        ];

        if (! empty($stored['url'])) {
            $data['url'] = (string) $stored['url'];
        }

        return $data;
    }
}
