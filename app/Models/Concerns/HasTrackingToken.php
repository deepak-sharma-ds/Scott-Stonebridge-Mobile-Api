<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Gives a delivery its permanent Open Tracking token on creation. The token
 * never changes, so every Send History copy of the email records engagement
 * against the same delivery.
 */
trait HasTrackingToken
{
    protected static function bootHasTrackingToken(): void
    {
        static::creating(function ($delivery): void {
            if (empty($delivery->tracking_token)) {
                $delivery->tracking_token = (string) Str::uuid();
            }
        });
    }
}
