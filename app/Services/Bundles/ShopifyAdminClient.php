<?php

namespace App\Services\Bundles;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Thin Shopify Admin GraphQL client for the bundle app.
 * Kept separate from the migration client (which is env/dry-run driven).
 */
class ShopifyAdminClient
{
    protected const MAX_RETRIES = 4;

    public function __construct(protected ShopifyBundleCredentials $credentials)
    {
    }

    public function credentials(): ShopifyBundleCredentials
    {
        return $this->credentials;
    }

    /**
     * Run a query/mutation and return the `data` object.
     * Throws on transport errors and top-level GraphQL errors.
     */
    public function query(string $query, array $variables = []): array
    {
        $endpoint = sprintf(
            'https://%s/admin/api/%s/graphql.json',
            $this->credentials->shopDomain(),
            config('bundles.api_version')
        );

        $payload = ['query' => $query];
        if ($variables) {
            $payload['variables'] = $variables;
        }

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = Http::withHeaders(['X-Shopify-Access-Token' => $this->credentials->accessToken()])
                    ->acceptJson()
                    ->timeout(30)
                    ->post($endpoint, $payload);
            } catch (ConnectionException $e) {
                throw new ShopifyApiException('Could not reach Shopify: ' . $e->getMessage());
            }

            if ($response->status() === 401 && $attempt === 0) {
                // Cached client-credentials token may have expired early — fetch a new one once.
                $this->credentials->forgetCachedToken();
                continue;
            }

            $throttled = $response->status() === 429
                || collect($response->json('errors', []))->contains(fn ($e) => ($e['extensions']['code'] ?? null) === 'THROTTLED');

            if ($throttled && $attempt < self::MAX_RETRIES) {
                sleep(2 ** $attempt);
                continue;
            }

            if (! $response->successful()) {
                throw new ShopifyApiException("Shopify API error [{$response->status()}]: " . $response->body());
            }

            if ($errors = $response->json('errors')) {
                throw new ShopifyApiException('Shopify GraphQL error: ' . collect($errors)->pluck('message')->implode('; '));
            }

            return $response->json('data') ?? [];
        }
    }

    /**
     * Run a mutation and throw if the payload returned userErrors.
     */
    public function mutate(string $operation, string $query, array $variables = []): array
    {
        $data = $this->query($query, $variables);
        $userErrors = $data[$operation]['userErrors'] ?? [];

        if ($userErrors) {
            $message = collect($userErrors)
                ->map(fn ($e) => trim(implode('.', (array) ($e['field'] ?? [])) . ' ' . $e['message']))
                ->implode('; ');

            throw new ShopifyApiException("{$operation}: {$message}", $userErrors, $operation);
        }

        return $data[$operation] ?? [];
    }
}
