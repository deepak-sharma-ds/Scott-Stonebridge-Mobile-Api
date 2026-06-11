<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyShopifyWebhookHmac
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('email_reading.hmac_enabled', false)) {
            $request->attributes->set('shopify_hmac_valid', false);
            $request->attributes->set('shopify_hmac_skipped', true);

            return $next($request);
        }

        $secret = (string) config('email_reading.shopify_webhook_secret');
        $headerHmac = (string) $request->header('X-Shopify-Hmac-Sha256', '');

        if ($secret === '' || $headerHmac === '') {
            Log::channel('shopify_webhooks')->warning('Shopify HMAC missing', [
                'path' => $request->path(),
                'has_secret' => $secret !== '',
                'has_header' => $headerHmac !== '',
            ]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $computed = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        if (! hash_equals($computed, $headerHmac)) {
            Log::channel('shopify_webhooks')->warning('Shopify HMAC mismatch', [
                'path' => $request->path(),
                'order_id' => $request->input('id'),
            ]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->attributes->set('shopify_hmac_valid', true);

        return $next($request);
    }
}
