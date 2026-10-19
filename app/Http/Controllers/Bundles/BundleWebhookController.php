<?php

namespace App\Http\Controllers\Bundles;

use App\Http\Controllers\Controller;
use App\Services\Bundles\BundleWebhookService;
use App\Services\Bundles\ShopifyBundleCredentials;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Receives app webhooks declared in shopify-bundle-app/shopify.app.toml.
 */
class BundleWebhookController extends Controller
{
    public function __invoke(Request $request, ShopifyBundleCredentials $credentials, BundleWebhookService $webhooks)
    {
        $secret = $credentials->webhookSecret();
        $expected = $secret ? base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true)) : null;

        if (! $expected || ! hash_equals($expected, (string) $request->header('X-Shopify-Hmac-Sha256'))) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        $shop = strtolower((string) $request->header('X-Shopify-Shop-Domain'));
        if ($shop !== $credentials->shopDomain()) {
            return response()->json(['message' => 'Unknown shop.'], 200);
        }

        try {
            $webhooks->handle((string) $request->header('X-Shopify-Topic'), $request->json()->all());
        } catch (Throwable $e) {
            // Log and still acknowledge; Shopify retries on non-2xx and the handler is only advisory.
            Log::error('Bundle webhook failed', ['topic' => $request->header('X-Shopify-Topic'), 'error' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }
}
