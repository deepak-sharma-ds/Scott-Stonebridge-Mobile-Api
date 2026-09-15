<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyShopifyWebhookSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
     public function handle(Request $request, Closure $next): Response
    {
         \Log::info('SHOPIFY WEBHOOK REQUEST RECEIVED', [
            'headers' => $request->headers->all(),
            'body' => $request->getContent(),
          ]);

        $hmacHeader = $request->header('X-Shopify-Hmac-Sha256');
        $secret = config('services.shopify.webhook_secret');

        if (! $hmacHeader || ! $secret) {
            return response('Missing signature or secret not configured', 401);
        }

        // IMPORTANT: use the raw request body, not $request->all() / json().
        // Re-encoding the parsed JSON will not match Shopify's signature.
        $calculated = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        if (! hash_equals($calculated, $hmacHeader)) {
            return response('Invalid signature', 401);
        }

        return $next($request);
    }
}
