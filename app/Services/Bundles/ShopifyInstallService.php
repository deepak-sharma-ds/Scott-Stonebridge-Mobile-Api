<?php

namespace App\Services\Bundles;

use App\Models\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Obtains the offline Admin API access token when the app is installed on a store
 * (e.g. from the distribution link) and saves it on the `stores` row, the same place
 * `php artisan bundles:use-store` writes to.
 *
 * Supports both install flows:
 *  - Managed install: Shopify opens the app URL with `id_token`; exchanged via token exchange.
 *  - Authorization code grant: Shopify/app redirects to the redirect URL with `code` + `hmac`.
 */
class ShopifyInstallService
{
    protected const SHOP_PATTERN = '/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/';

    protected const LEEWAY = 10;

    protected const STATE_TTL_MINUTES = 10;

    public function __construct(protected ShopifyBundleCredentials $credentials) {}

    public function credentials(): ShopifyBundleCredentials
    {
        return $this->credentials;
    }

    public function normalizeShop(?string $shop): ?string
    {
        $shop = strtolower(trim((string) $shop));

        return preg_match(self::SHOP_PATTERN, $shop) ? $shop : null;
    }

    public function hasToken(string $shop): bool
    {
        $store = Store::where('domain', $shop)->first();

        return $store !== null && (string) $store->app_admin_access_token !== '';
    }

    /**
     * Managed install: verify the App Bridge session token, then swap it for an offline access token.
     */
    public function installFromSessionToken(string $idToken): ?Store
    {
        $shop = $this->shopFromSessionToken($idToken);

        if ($shop === null) {
            return null;
        }

        if ($this->hasToken($shop)) {
            return Store::where('domain', $shop)->first();
        }

        $response = Http::asForm()->timeout(20)->post("https://{$shop}/admin/oauth/access_token", [
            'client_id' => $this->credentials->clientId(),
            'client_secret' => $this->credentials->clientSecret(),
            'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
            'subject_token' => $idToken,
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
            'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
        ]);

        return $this->saveFromResponse($shop, $response->successful() ? $response->json() : null, $response->status());
    }

    /**
     * Authorization code grant: verify the callback query (HMAC + state), then swap the code for a token.
     *
     * @param  array<string, mixed>  $query
     */
    public function installFromAuthorizationCode(array $query): ?Store
    {
        $shop = $this->normalizeShop($query['shop'] ?? null);

        if ($shop === null || ! $this->isValidHmac($query) || empty($query['code'])) {
            return null;
        }

        $state = (string) ($query['state'] ?? '');
        if ($state === '' || Cache::pull($this->stateKey($state)) !== $shop) {
            return null;
        }

        $response = Http::asForm()->timeout(20)->post("https://{$shop}/admin/oauth/access_token", [
            'client_id' => $this->credentials->clientId(),
            'client_secret' => $this->credentials->clientSecret(),
            'code' => (string) $query['code'],
        ]);

        return $this->saveFromResponse($shop, $response->successful() ? $response->json() : null, $response->status());
    }

    /**
     * Where to send the merchant to grant access (authorization code grant).
     */
    public function authorizationUrl(string $shop, string $redirectUri): string
    {
        $state = Str::random(40);
        Cache::put($this->stateKey($state), $shop, now()->addMinutes(self::STATE_TTL_MINUTES));

        return "https://{$shop}/admin/oauth/authorize?".http_build_query([
            'client_id' => $this->credentials->clientId(),
            'scope' => implode(',', BundleSetupService::REQUIRED_SCOPES),
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    /**
     * A query string signed by Shopify (install request or OAuth callback).
     *
     * @param  array<string, mixed>  $query
     */
    public function isValidHmac(array $query): bool
    {
        $secret = $this->credentials->clientSecret();
        $hmac = (string) ($query['hmac'] ?? '');

        if (! $secret || $hmac === '') {
            return false;
        }

        unset($query['hmac'], $query['signature']);
        ksort($query);

        $message = collect($query)
            ->map(fn ($value, $key) => $key.'='.(is_array($value) ? '["'.implode('", "', $value).'"]' : $value))
            ->implode('&');

        return hash_equals(hash_hmac('sha256', $message, $secret), $hmac);
    }

    /**
     * Shop domain from a valid session token, or null if the token is not valid for this app.
     */
    protected function shopFromSessionToken(string $token): ?string
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

        return $this->normalizeShop(parse_url((string) ($claims['dest'] ?? ''), PHP_URL_HOST) ?: null);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    protected function saveFromResponse(string $shop, ?array $payload, int $status): Store
    {
        $token = (string) ($payload['access_token'] ?? '');

        if ($token === '') {
            throw new ShopifyApiException("Could not obtain an access token from {$shop} [{$status}].");
        }

        $store = Store::firstOrNew(['domain' => $shop]);
        $store->name = $store->name ?: $shop;
        $store->app_client_id = $store->app_client_id ?: $this->credentials->clientId();
        $store->app_secret_key = $store->app_secret_key ?: $this->credentials->clientSecret();
        $store->webhook_secret = $store->webhook_secret ?: $this->credentials->clientSecret();
        $store->app_admin_access_token = $token;
        $store->status = true;
        $store->save();

        return $store;
    }

    protected function stateKey(string $state): string
    {
        return 'bundles.install_state.'.$state;
    }

    protected function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4));
    }
}
