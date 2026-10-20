<?php

namespace App\Services\Bundles;

use Throwable;

/**
 * Connection diagnostics and one-off setup for the bundle app.
 */
class BundleSetupService
{
    public const REQUIRED_SCOPES = ['read_products', 'write_products', 'read_inventory', 'read_publications', 'write_publications', 'read_discounts', 'write_discounts', 'read_orders'];

    public function __construct(
        protected ShopifyAdminClient $client,
        protected BundleSyncService $sync,
    ) {
    }

    public function diagnostics(): array
    {
        $credentials = $this->client->credentials();

        $result = [
            'configured'      => $credentials->isConfigured(),
            'shop_domain'     => $credentials->shopDomain(),
            'token_source'    => $credentials->isConfigured() ? $credentials->tokenSource() : null,
            'api_version'     => config('bundles.api_version'),
            'connected'       => false,
            'error'           => null,
            'shop'            => null,
            'scopes'          => [],
            'missing_scopes'  => [],
            'publication_id'  => null,
            'cart_transforms' => [],
            'functions'       => [],
        ];

        if (! $result['configured']) {
            return $result;
        }

        try {
            $data = $this->client->query(<<<'GQL'
                {
                    shop { name myshopifyDomain currencyCode plan { displayName partnerDevelopment shopifyPlus } }
                    currentAppInstallation { accessScopes { handle } }
                }
            GQL);

            $result['connected'] = true;
            $result['shop'] = $data['shop'] ?? null;
            $result['scopes'] = collect($data['currentAppInstallation']['accessScopes'] ?? [])->pluck('handle')->all();
            $result['missing_scopes'] = array_values(array_diff(self::REQUIRED_SCOPES, $result['scopes']));
            $result['publication_id'] = $this->sync->onlineStorePublicationId();
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();

            return $result;
        }

        // Optional: only relevant if Discovery shows fixed bundles need a Cart Transform on this store.
        if (in_array('read_cart_transforms', $result['scopes'], true)) {
            try {
                $data = $this->client->query(<<<'GQL'
                    {
                        cartTransforms(first: 5) { nodes { id functionId } }
                        shopifyFunctions(first: 25) { nodes { id title apiType } }
                    }
                GQL);
                $result['cart_transforms'] = $data['cartTransforms']['nodes'] ?? [];
                $result['functions'] = collect($data['shopifyFunctions']['nodes'] ?? [])
                    ->where('apiType', 'cart_transform')->values()->all();
            } catch (Throwable $e) {
                $result['error'] = 'Cart transform check failed: ' . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Register the app's (no-op) cart transform function on the store.
     */
    public function registerCartTransform(string $functionId): array
    {
        return $this->client->mutate('cartTransformCreate', <<<'GQL'
            mutation($functionId: String!) {
                cartTransformCreate(functionId: $functionId) {
                    cartTransform { id functionId }
                    userErrors { field message }
                }
            }
        GQL, ['functionId' => $functionId]);
    }
}
