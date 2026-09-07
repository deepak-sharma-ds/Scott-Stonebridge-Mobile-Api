<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Klaviyo flow webhooks carry no signature — the payload is marketer-defined.
 * Authentication is therefore a shared secret the marketer sets as the
 * X-Klaviyo-Webhook-Secret header on the flow's Webhook action. A header
 * (not a URL token) is used so the secret never lands in access logs.
 */
class VerifyKlaviyoWebhookSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('push.klaviyo.webhook_auth_enabled', true)) {
            return $next($request);
        }

        $expected = (string) config('push.klaviyo.webhook_secret');
        $provided = (string) $request->header('X-Klaviyo-Webhook-Secret', '');

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            Log::channel('push')->warning('Klaviyo webhook secret rejected', [
                'path' => $request->path(),
                'has_secret' => $expected !== '',
                'has_header' => $provided !== '',
            ]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
