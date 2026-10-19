<?php

namespace App\Http\Middleware;

use App\Services\Bundles\ShopifyBundleCredentials;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates requests from the embedded Shopify app (App Bridge session token).
 *
 * The token is a HS256 JWT signed with the app's client secret. We check the signature,
 * expiry, audience (our client ID) and that it was issued for the configured shop.
 * https://shopify.dev/docs/apps/build/authentication-authorization/session-tokens
 */
class VerifyShopifySessionToken
{
    protected const LEEWAY = 10; // seconds of clock skew allowed

    public function __construct(protected ShopifyBundleCredentials $credentials)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $claims = $this->verify((string) $request->bearerToken());

        if (! $claims) {
            return response()->json(['message' => 'Invalid or expired Shopify session. Reload the app.'], 401)
                ->header('X-Shopify-Retry-Invalid-Session-Request', '1');
        }

        $request->attributes->set('shopify_session', $claims);

        return $next($request);
    }

    public function verify(string $token): ?array
    {
        $secret = $this->credentials->clientSecret();
        $parts = explode('.', $token);

        if (! $secret || count($parts) !== 3) {
            return null;
        }

        [$header64, $payload64, $signature64] = $parts;
        $header = json_decode($this->base64UrlDecode($header64), true);
        $claims = json_decode($this->base64UrlDecode($payload64), true);

        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? null) !== 'HS256') {
            return null;
        }

        $expected = hash_hmac('sha256', "{$header64}.{$payload64}", $secret, true);
        if (! hash_equals($expected, $this->base64UrlDecode($signature64))) {
            return null;
        }

        $now = time();
        if (($claims['exp'] ?? 0) < $now - self::LEEWAY || ($claims['nbf'] ?? 0) > $now + self::LEEWAY) {
            return null;
        }

        if (($claims['aud'] ?? null) !== $this->credentials->clientId()) {
            return null;
        }

        $shop = strtolower((string) parse_url((string) ($claims['dest'] ?? ''), PHP_URL_HOST));
        if ($shop === '' || $shop !== $this->credentials->shopDomain()) {
            return null;
        }

        return $claims;
    }

    protected function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4));
    }
}
