<?php

namespace App\Services\Bundles;

use App\Models\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Resolves the shop domain + Admin API token used by the bundle app.
 */
class ShopifyBundleCredentials
{
    protected ?Store $store = null;

    public function __construct()
    {
        if ($storeId = config('bundles.store_id')) {
            $this->store = Store::find($storeId);
        }
    }

    public function shopDomain(): string
    {
        $domain = $this->store?->domain ?: config('bundles.shop_domain');

        return strtolower(preg_replace('#^https?://#i', '', rtrim((string) $domain, '/')));
    }

    public function clientId(): ?string
    {
        return $this->store?->app_client_id ?: config('bundles.client_id');
    }

    public function clientSecret(): ?string
    {
        return $this->store?->app_secret_key ?: config('bundles.client_secret');
    }

    /**
     * Secret used to verify webhook HMACs. App webhooks are signed with the app client secret.
     */
    public function webhookSecret(): ?string
    {
        return $this->store?->webhook_secret ?: $this->clientSecret();
    }

    public function isConfigured(): bool
    {
        return $this->shopDomain() !== ''
            && ($this->staticToken() || ($this->clientId() && $this->clientSecret()));
    }

    public function tokenSource(): string
    {
        if ($this->staticToken()) {
            return $this->store ? 'stores table (admin access token)' : 'BUNDLE_ADMIN_ACCESS_TOKEN';
        }

        return 'client credentials grant';
    }

    public function accessToken(): string
    {
        if ($token = $this->staticToken()) {
            return $token;
        }

        if (! $this->clientId() || ! $this->clientSecret() || $this->shopDomain() === '') {
            throw new ShopifyApiException('Bundle app is not configured: set the shop domain and either an access token or client ID/secret.');
        }

        $cacheKey = 'bundles.shopify_token.' . md5($this->shopDomain() . $this->clientId());

        return Cache::get($cacheKey) ?? $this->requestClientCredentialsToken($cacheKey);
    }

    public function forgetCachedToken(): void
    {
        Cache::forget('bundles.shopify_token.' . md5($this->shopDomain() . $this->clientId()));
    }

    protected function staticToken(): ?string
    {
        return $this->store?->app_admin_access_token ?: config('bundles.access_token');
    }

    protected function requestClientCredentialsToken(string $cacheKey): string
    {
        $response = Http::asForm()->timeout(20)->post("https://{$this->shopDomain()}/admin/oauth/access_token", [
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId(),
            'client_secret' => $this->clientSecret(),
        ]);

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new ShopifyApiException(
                "Could not obtain an access token from {$this->shopDomain()} [{$response->status()}]. "
                . 'Make sure the app is installed on the store and the client ID/secret are correct.'
            );
        }

        $ttl = max(60, (int) $response->json('expires_in', 86399) - 300);
        Cache::put($cacheKey, $response->json('access_token'), $ttl);

        return $response->json('access_token');
    }
}
