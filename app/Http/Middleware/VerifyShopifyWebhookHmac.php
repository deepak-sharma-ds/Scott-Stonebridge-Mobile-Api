<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional Shopify webhook HMAC verification, configured per module.
 *
 * Usage: `shopify.hmac` (reading flow, config namespace `email_reading`) or
 * `shopify.hmac:campaign_email`. Each namespace has its own `hmac_enabled`
 * flag (default false) and `shopify_webhook_secret`. When disabled the
 * request passes through unverified.
 */
class VerifyShopifyWebhookHmac
{
    public function handle(Request $request, Closure $next, string $configNamespace = 'email_reading'): Response
    {
        if (! config("{$configNamespace}.hmac_enabled", false)) {
            $request->attributes->set('shopify_hmac_valid', false);
            $request->attributes->set('shopify_hmac_skipped', true);

            return $next($request);
        }

        $secret = (string) config("{$configNamespace}.shopify_webhook_secret");
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
            ]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->attributes->set('shopify_hmac_valid', true);

        return $next($request);
    }
}
