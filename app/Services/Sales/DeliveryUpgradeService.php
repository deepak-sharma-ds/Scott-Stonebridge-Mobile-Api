<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Contracts\Services\Sales\DeliveryUpgradeServiceInterface;
use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\DTOs\Sales\UpsellSuggestionDTO;
use App\Services\AI\ChatbotConfigRepository;
use App\Services\Base\BaseService;
use App\Services\CurrencyCountryMapService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Discovers Delivery Upgrade Products by Shopify tag and decides whether a
 * cart holds an eligible reading (ADR 0022).
 *
 * Every Storefront lookup is cached for an hour (ADR 0021). Failures are
 * logged on the `ai` channel and swallowed — callers get an empty list /
 * false, so recommendations degrade to today's behaviour instead of erroring.
 */
class DeliveryUpgradeService extends BaseService implements DeliveryUpgradeServiceInterface
{
    public const UPGRADE_TAG = 'delivery-upgrade';

    private const CACHE_TTL_SECONDS = 3600;

    /** Short TTL for an empty upgrade list, so a newly tagged product appears quickly. */
    private const EMPTY_CACHE_TTL_SECONDS = 60;

    /** Upper bound on cart products inspected per call. */
    private const MAX_CART_PRODUCTS = 20;

    public function __construct(
        private readonly StorefrontApiClientInterface $storefront,
        private readonly ChatbotConfigRepository $config,
    ) {
        parent::__construct();
    }

    public function getUpgradeProducts(string $shopDomain, ?string $currency = null): array
    {
        if ($shopDomain === '' || ! $this->config->deliveryUpgradeEnabled()) {
            return [];
        }

        $country = CurrencyCountryMapService::getCountryCode((string) ($currency ?? 'GBP'));

        try {
            $nodes = $this->remember(
                sprintf('ai:delivery_upgrade:products:%s:%s', $shopDomain, $country),
                fn (): array => $this->fetchUpgradeNodes($country),
            );
        } catch (Throwable $e) {
            $this->logWarning('Delivery upgrade product lookup failed', [
                'shop' => $shopDomain,
                'error' => $e->getMessage(),
            ], 'ai');

            return [];
        }

        $products = [];
        foreach ($nodes as $node) {
            $dto = UpsellSuggestionDTO::fromShopifyNode($node, $currency ?? 'GBP', UpsellSuggestionDTO::TYPE_DELIVERY_UPGRADE);
            if ($dto !== null && $dto->available) {
                $products[] = $dto;
            }
        }

        return $products;
    }

    public function cartHasEligibleReading(array $cartItems, string $shopDomain): bool
    {
        if ($cartItems === [] || $shopDomain === '' || ! $this->config->deliveryUpgradeEnabled()) {
            return false;
        }

        $productIds = $this->extractProductGids($cartItems);
        if ($productIds === []) {
            return false;
        }

        try {
            $facts = $this->productFacts($productIds, $shopDomain);
        } catch (Throwable $e) {
            $this->logWarning('Delivery upgrade cart eligibility lookup failed', [
                'shop' => $shopDomain,
                'error' => $e->getMessage(),
            ], 'ai');

            return false;
        }

        $eligibleHandles = $this->config->deliveryUpgradeEligibleCollectionHandles();

        foreach ($facts as $fact) {
            if (in_array(self::UPGRADE_TAG, $fact['tags'], true)) {
                continue;
            }

            if (array_intersect($fact['collections'], $eligibleHandles) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchUpgradeNodes(string $country): array
    {
        $response = $this->storefront->query('storefront/products/get_delivery_upgrade_products', [
            'query' => 'tag:"'.self::UPGRADE_TAG.'"',
            'country' => $country,
        ]);

        $nodes = [];
        foreach ($response['data']['products']['edges'] ?? [] as $edge) {
            $node = $edge['node'] ?? null;
            if (! is_array($node)) {
                continue;
            }

            // The search index can lag behind tag edits; trust the node's own tags.
            $tags = array_map('strtolower', (array) ($node['tags'] ?? []));
            if (in_array(self::UPGRADE_TAG, $tags, true)) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * Tags and collection handles per cart product, cached per product so
     * eligible-collection config changes apply without flushing the cache.
     *
     * @param  list<string>  $productGids
     * @return array<string, array{tags: list<string>, collections: list<string>}>
     */
    private function productFacts(array $productGids, string $shopDomain): array
    {
        $facts = [];
        $missing = [];

        foreach ($productGids as $gid) {
            $cached = Cache::get($this->factsKey($shopDomain, $gid));
            if (is_array($cached)) {
                $facts[$gid] = $cached;
            } else {
                $missing[] = $gid;
            }
        }

        if ($missing === []) {
            return $facts;
        }

        $response = $this->storefront->query('storefront/products/get_products_collection_handles', [
            'ids' => $missing,
        ]);

        $fetched = [];
        foreach ($response['data']['nodes'] ?? [] as $node) {
            if (! is_array($node) || ! isset($node['id'])) {
                continue;
            }

            $collections = [];
            foreach ($node['collections']['edges'] ?? [] as $edge) {
                $handle = strtolower(trim((string) ($edge['node']['handle'] ?? '')));
                if ($handle !== '') {
                    $collections[] = $handle;
                }
            }

            $fetched[(string) $node['id']] = [
                'tags' => array_map(static fn ($tag): string => strtolower(trim((string) $tag)), (array) ($node['tags'] ?? [])),
                'collections' => $collections,
            ];
        }

        foreach ($missing as $gid) {
            // A product Shopify no longer returns is cached as having no facts
            // so it is not re-queried on every turn.
            $fact = $fetched[$gid] ?? ['tags' => [], 'collections' => []];
            Cache::put($this->factsKey($shopDomain, $gid), $fact, self::CACHE_TTL_SECONDS);
            $facts[$gid] = $fact;
        }

        return $facts;
    }

    /**
     * Cache-miss lock mirroring UpsellService: one worker hits Shopify while
     * the rest wait briefly for the warmed cache.
     *
     * @param  callable(): array<int, array<string, mixed>>  $fetch
     * @return array<int, array<string, mixed>>
     */
    private function remember(string $key, callable $fetch): array
    {
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $lock = Cache::lock($key.':lock', 5);

        try {
            $lock->block(2);

            // The previous lock holder may have just warmed the cache.
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }

            $nodes = $fetch();

            // An empty result is only held briefly so a product tagged in
            // Shopify shows up within a minute, not an hour.
            Cache::put($key, $nodes, $nodes === [] ? self::EMPTY_CACHE_TTL_SECONDS : self::CACHE_TTL_SECONDS);

            return $nodes;
        } catch (LockTimeoutException) {
            $cached = Cache::get($key);

            return is_array($cached) ? $cached : $fetch();
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * @param  list<array{product_id?: string, id?: string, quantity?: int}>  $cartItems
     * @return list<string>
     */
    private function extractProductGids(array $cartItems): array
    {
        $gids = [];
        foreach ($cartItems as $item) {
            $gid = $this->toProductGid((string) ($item['product_id'] ?? $item['id'] ?? ''));
            if ($gid !== '') {
                $gids[$gid] = $gid;
            }

            if (count($gids) >= self::MAX_CART_PRODUCTS) {
                break;
            }
        }

        return array_values($gids);
    }

    /**
     * Storefront `nodes(ids:)` rejects bare numeric ids, so coerce cart.js
     * product ids to a real Product GID.
     */
    private function toProductGid(string $productId): string
    {
        $productId = trim($productId);
        if ($productId === '' || str_starts_with($productId, 'gid://shopify/Product/')) {
            return $productId;
        }

        if (preg_match('~/(\d+)$~', $productId, $m) === 1) {
            return 'gid://shopify/Product/'.$m[1];
        }

        return ctype_digit($productId) ? 'gid://shopify/Product/'.$productId : '';
    }

    private function factsKey(string $shopDomain, string $gid): string
    {
        return sprintf('ai:delivery_upgrade:facts:%s:%s', $shopDomain, md5($gid));
    }
}
