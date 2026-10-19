<?php

namespace App\Http\Middleware;

use App\Services\Bundles\ShopifyBundleCredentials;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies requests forwarded by the Shopify app proxy (/apps/zymox-bundles/* on the storefront).
 * https://shopify.dev/docs/apps/build/online-store/display-dynamic-data#calculate-a-digital-signature
 */
class VerifyAppProxySignature
{
    protected const MAX_AGE = 300; // seconds

    public function __construct(protected ShopifyBundleCredentials $credentials)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->valid($request->query())) {
            return response()->json(['message' => 'Invalid proxy signature.'], 401);
        }

        return $next($request);
    }

    public function valid(array $query): bool
    {
        $secret = $this->credentials->clientSecret();
        $signature = (string) ($query['signature'] ?? '');
        unset($query['signature']);

        if (! $secret || $signature === '') {
            return false;
        }
        if (strtolower((string) ($query['shop'] ?? '')) !== $this->credentials->shopDomain()) {
            return false;
        }
        if (abs(time() - (int) ($query['timestamp'] ?? 0)) > self::MAX_AGE) {
            return false;
        }

        ksort($query);
        $message = implode('', array_map(
            fn ($key, $value) => $key . '=' . (is_array($value) ? implode(',', $value) : $value),
            array_keys($query),
            $query
        ));

        return hash_equals(hash_hmac('sha256', $message, $secret), $signature);
    }
}
