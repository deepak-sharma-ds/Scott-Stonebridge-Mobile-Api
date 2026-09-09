<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when FCM reports the device token is no longer valid
 * (UNREGISTERED / INVALID_ARGUMENT). The caller should revoke the token
 * and must NOT retry the send.
 */
class PushTokenInvalidException extends Exception
{
    //
}
