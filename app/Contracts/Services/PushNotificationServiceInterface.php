<?php

namespace App\Contracts\Services;

use App\Exceptions\PushTokenInvalidException;
use App\Models\PushNotification;

interface PushNotificationServiceInterface
{
    /**
     * Deliver the given pending notification to its device via FCM.
     *
     * @return string The FCM message id
     *
     * @throws PushTokenInvalidException When the device token is dead (do not retry)
     * @throws \Exception On transient FCM failures (safe to retry)
     */
    public function send(PushNotification $notification): string;
}
