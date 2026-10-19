<?php

namespace App\Services\Bundles;

use App\Models\Bundles\Bundle;
use Illuminate\Support\Facades\Cache;

/**
 * Maintains the storefront "bundle offer" mapping.
 *
 * Every product that should promote bundles (component products + admin-chosen extras) gets a
 * product metafield zymox_bundle.offers (list.product_reference) pointing to the active bundle
 * products. The theme blocks read live price/stock from those referenced bundle products.
 */
class BundleOfferService
{
    public const KEY = 'offers';

    public function __construct(protected ShopifyAdminClient $client)
    {
    }

    /**
     * Re-sync every product affected by this bundle (previous and current targets).
     * Returns a short summary for the sync log.
     */
    public function sync(Bundle $bundle, bool $removed = false): string
    {
        $bundle->loadMissing('components');

        $current = $removed ? [] : $bundle->offerTargetProductIds();
        $affected = array_values(array_unique(array_merge($bundle->offer_synced_product_ids ?? [], $current)));

        if ($affected) {
            $this->ensureDefinition();
            $this->writeProducts($affected, $removed ? $bundle->id : null);
        }

        $bundle->forceFill(['offer_synced_product_ids' => $current])->saveQuietly();

        return sprintf('Storefront offer shown on %d product(s)', count($current));
    }

    /**
     * For each product, collect all live bundles that target it and write (or clear) the metafield.
     */
    protected function writeProducts(array $productIds, ?int $excludeBundleId): void
    {
        $bundles = Bundle::with('components')
            ->where('status', Bundle::STATUS_ACTIVE)
            ->whereNotNull('shopify_product_id')
            ->when($excludeBundleId, fn ($q) => $q->where('id', '!=', $excludeBundleId))
            ->orderBy('id')
            ->get();

        $offersByProduct = [];
        foreach ($bundles as $bundle) {
            foreach ($bundle->offerTargetProductIds() as $productId) {
                $offersByProduct[$productId][] = $bundle->shopify_product_id;
            }
        }

        $set = [];
        $delete = [];
        foreach ($productIds as $productId) {
            $refs = array_values(array_unique($offersByProduct[$productId] ?? []));

            if ($refs) {
                $set[] = [
                    'ownerId'   => $productId,
                    'namespace' => config('bundles.metafield_namespace'),
                    'key'       => self::KEY,
                    'type'      => 'list.product_reference',
                    'value'     => json_encode($refs),
                ];
            } else {
                $delete[] = [
                    'ownerId'   => $productId,
                    'namespace' => config('bundles.metafield_namespace'),
                    'key'       => self::KEY,
                ];
            }
        }

        foreach (array_chunk($set, 25) as $chunk) {
            $this->client->mutate('metafieldsSet', <<<'GQL'
                mutation($metafields: [MetafieldsSetInput!]!) {
                    metafieldsSet(metafields: $metafields) { metafields { id } userErrors { field message } }
                }
            GQL, ['metafields' => $chunk]);
        }

        foreach (array_chunk($delete, 25) as $chunk) {
            $this->client->mutate('metafieldsDelete', <<<'GQL'
                mutation($metafields: [MetafieldIdentifierInput!]!) {
                    metafieldsDelete(metafields: $metafields) { deletedMetafields { key } userErrors { field message } }
                }
            GQL, ['metafields' => $chunk]);
        }
    }

    /**
     * Create the product metafield definition once so the reference list validates and
     * shows up in the Shopify admin. "Already exists" is fine.
     */
    protected function ensureDefinition(): void
    {
        $cacheKey = 'bundles.offer_definition.' . md5($this->client->credentials()->shopDomain());
        if (Cache::get($cacheKey)) {
            return;
        }

        $data = $this->client->query(<<<'GQL'
            mutation($definition: MetafieldDefinitionInput!) {
                metafieldDefinitionCreate(definition: $definition) {
                    createdDefinition { id }
                    userErrors { field message code }
                }
            }
        GQL, ['definition' => [
            'name'        => 'Bundle offers',
            'namespace'   => config('bundles.metafield_namespace'),
            'key'         => self::KEY,
            'description' => 'Managed by the Zymox Bundles app. Bundles promoted on this product.',
            'type'        => 'list.product_reference',
            'ownerType'   => 'PRODUCT',
        ]]);

        $errors = collect($data['metafieldDefinitionCreate']['userErrors'] ?? [])
            ->reject(fn ($e) => ($e['code'] ?? null) === 'TAKEN');

        if ($errors->isNotEmpty()) {
            throw new ShopifyApiException('metafieldDefinitionCreate: ' . $errors->pluck('message')->implode('; '));
        }

        Cache::forever($cacheKey, true);
    }
}
