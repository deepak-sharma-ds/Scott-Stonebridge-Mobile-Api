<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Contracts\Services\Sales\UpsellServiceInterface;
use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\DTOs\Sales\UpsellSuggestionDTO;
use App\Models\ShopSetting;
use App\Services\Base\BaseService;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Upsell / cross-sell intelligence backed by Shopify Storefront
 * `productRecommendations`. Every Storefront call is cached for
 * config('sales.upsell.cache_ttl') seconds so a cart with N items only
 * hits Shopify N times on first request, then 0 for subsequent views
 * during the cache window.
 *
 * Free-shipping threshold currently reads from
 * config('sales.upsell.default_free_shipping_threshold'). Step 10 wires
 * this to shop_settings.free_shipping_threshold (per-shop override).
 */
class UpsellService extends BaseService implements UpsellServiceInterface
{
    public function __construct(
        private readonly StorefrontApiClientInterface $storefront,
    ) {
        parent::__construct();
    }

    public function getUpsells(array $cartItems, string $shopDomain, ?string $currency = null): array
    {
        if ($cartItems === [] || $shopDomain === '') {
            return [];
        }

        $cartIds = $this->extractCartProductIds($cartItems);
        if ($cartIds === []) {
            return [];
        }

        $maxResults = (int) config('sales.upsell.max_results', 3);
        $country = $this->countryFromCurrency($currency);

        $suggestions = [];
        $seen = $cartIds; // dedupe vs cart + against itself

        foreach (array_keys($cartIds) as $productId) {
            try {
                $nodes = $this->recommendationsForProduct((string) $productId, $shopDomain, $country);
            } catch (Throwable $e) {
                $this->logWarning('Storefront productRecommendations failed', [
                    'product_id' => $productId,
                    'shop' => $shopDomain,
                    'error' => $e->getMessage(),
                ], 'ai');

                continue;
            }

            foreach ($nodes as $node) {
                $id = (string) ($node['id'] ?? '');
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }

                $dto = UpsellSuggestionDTO::fromShopifyNode($node, $currency ?? 'GBP');
                if ($dto === null || ! $dto->available) {
                    continue;
                }

                $suggestions[] = $dto;
                $seen[$id] = true;

                if (count($suggestions) >= $maxResults) {
                    return $suggestions;
                }
            }
        }

        return $suggestions;
    }

    public function getCrossSells(string $productId, string $shopDomain, ?string $currency = null): array
    {
        if ($productId === '' || $shopDomain === '') {
            return [];
        }

        $maxResults = (int) config('sales.upsell.max_results', 3);

        try {
            $nodes = $this->recommendationsForProduct(
                $productId,
                $shopDomain,
                $this->countryFromCurrency($currency),
            );
        } catch (Throwable $e) {
            $this->logWarning('Storefront productRecommendations failed (cross-sell)', [
                'product_id' => $productId,
                'shop' => $shopDomain,
                'error' => $e->getMessage(),
            ], 'ai');

            return [];
        }

        $out = [];
        foreach ($nodes as $node) {
            if ((string) ($node['id'] ?? '') === $productId) {
                continue;
            }
            $dto = UpsellSuggestionDTO::fromShopifyNode($node, $currency ?? 'GBP');
            if ($dto === null || ! $dto->available) {
                continue;
            }
            $out[] = $dto;
            if (count($out) >= $maxResults) {
                break;
            }
        }

        return $out;
    }

    public function getFreeShippingGap(float $cartTotal, string $shopDomain): ?float
    {
        $threshold = $this->freeShippingThreshold($shopDomain);
        if ($threshold === null || $threshold <= 0.0) {
            return null;
        }

        $gap = $threshold - $cartTotal;

        return $gap > 0.0 ? round($gap, 2) : null;
    }

    /**
     * The merchant's free-shipping threshold for a shop. Looks up
     * shop_settings.free_shipping_threshold first; falls back to
     * config('sales.upsell.default_free_shipping_threshold').
     */
    protected function freeShippingThreshold(string $shopDomain): ?float
    {
        if ($shopDomain !== '') {
            try {
                $perShop = ShopSetting::query()
                    ->where('shop_domain', $shopDomain)
                    ->value('free_shipping_threshold');
                if ($perShop !== null) {
                    return (float) $perShop;
                }
            } catch (Throwable $e) {
                $this->logWarning('ShopSetting threshold lookup failed', [
                    'shop' => $shopDomain,
                    'error' => $e->getMessage(),
                ], 'ai');
            }
        }

        $default = config('sales.upsell.default_free_shipping_threshold');

        return $default === null ? null : (float) $default;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recommendationsForProduct(string $productId, string $shopDomain, string $country): array
    {
        $ttl = (int) config('sales.upsell.cache_ttl', 600);
        $key = sprintf('ai:upsell:%s:%s:%s', $shopDomain, $country, md5($productId));

        return Cache::remember($key, $ttl, function () use ($productId, $country): array {
            $response = $this->storefront->query('storefront/products/get_product_recommendations', [
                'productId' => $productId,
                'country' => $country,
            ]);

            $nodes = $response['data']['productRecommendations'] ?? [];

            return is_array($nodes) ? array_values($nodes) : [];
        });
    }

    /**
     * @param  list<array{product_id?: string, id?: string, quantity?: int}>  $cartItems
     * @return array<string, true>
     */
    private function extractCartProductIds(array $cartItems): array
    {
        $ids = [];
        foreach ($cartItems as $item) {
            $id = (string) ($item['product_id'] ?? $item['id'] ?? '');
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    private function countryFromCurrency(?string $currency): string
    {
        return match (strtoupper((string) $currency)) {
            'USD' => 'US',
            'EUR' => 'DE',
            'CAD' => 'CA',
            'AUD' => 'AU',
            'INR' => 'IN',
            default => 'GB',
        };
    }
}
