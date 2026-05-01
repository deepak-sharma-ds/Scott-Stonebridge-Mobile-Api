<?php

namespace App\Services\Shopify;

use App\Contracts\Services\ProductServiceInterface;
use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\DTOs\Product\ProductDTO;
use App\DTOs\Product\CollectionDTO;
use App\Services\Base\BaseService;
use App\Services\Cache\ShopifyCacheStrategy;
use App\Traits\CacheWithFallback;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ProductService extends BaseService implements ProductServiceInterface
{
    use CacheWithFallback;
    public function __construct(
        protected StorefrontApiClientInterface $storefrontClient,
        protected ShopifyCacheStrategy $cacheStrategy
    ) {
        parent::__construct();
    }

    /**
     * Get all products with pagination
     *
     * @param int $limit Number of products to fetch
     * @param string|null $cursor Pagination cursor
     * @param array $filters Additional filters
     * @return Collection Collection of ProductDTO instances
     */
    public function getAllProducts(int $limit, ?string $cursor, array $filters): array
    {
        try {
            $this->logPerformanceStart('getAllProducts');

            $variables = [
                'limit' => $limit,
                'after' => $cursor,
                'sortKey' => strtoupper((string) ($filters['sortKey'] ?? 'TITLE')),
                'reverse' => filter_var($filters['reverse'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'query' => $filters['query'] ?? null,
                'country' => $this->getCurrencyCountryCode(),
            ];

            $response = $this->storefrontClient->queryWithCurrency('storefront/products/get_all_products', $variables);

            $products = collect($response['data']['products']['edges'] ?? [])
                ->map(fn($edge) => ProductDTO::fromShopifyResponse($edge['node']));

            $pageInfo = $response['data']['products']['pageInfo'] ?? [];

            $this->logPerformanceEnd('getAllProducts', [
                'count' => $products->count(),
                'has_next_page' => $pageInfo['hasNextPage'] ?? false,
            ]);

            return [
                'items' => $products,
                'pagination' => [
                    'has_more' => $pageInfo['hasNextPage'] ?? false,
                    'next_cursor' => $pageInfo['endCursor'] ?? null,
                ],
            ];
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch products', $e, [
                'limit' => $limit,
                'cursor' => $cursor,
                'filters' => $filters,
            ]);
            throw $e;
        }
    }


    /**
     * Get a single product by handle
     *
     * @param string $handle Product handle
     * @return ProductDTO
     */
    public function getProductByHandle(string $handle): ProductDTO
    {
        try {
            $this->logPerformanceStart('getProductByHandle');

            $variables = [
                'handle' => $handle,
                'country' => $this->getCurrencyCountryCode(),
            ];

            $response = $this->storefrontClient->queryWithCurrency('storefront/products/get_product_details', $variables);

            if (empty($response['data']['productByHandle'])) {
                throw new \App\Exceptions\ShopifyNotFoundException("Product not found: {$handle}");
            }

            $product = ProductDTO::fromShopifyResponse($response['data']['productByHandle']);

            $this->logPerformanceEnd('getProductByHandle', ['handle' => $handle]);

            return $product;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch product by handle', $e, ['handle' => $handle]);
            throw $e;
        }
    }

    /**
     * Search products by query
     *
     * @param string $query Search query
     * @param int $limit Number of products to fetch
     * @param string|null $cursor Pagination cursor
     * @return array Array with 'items' and 'pagination' keys
     */
    public function searchProducts(string $query, int $limit, ?string $cursor): array
    {
        try {
            $this->logPerformanceStart('searchProducts');

            $variables = [
                'limit' => $limit,
                'after' => $cursor,
                'query' => $query,
                'country' => $this->getCurrencyCountryCode(),
            ];

            $response = $this->storefrontClient->queryWithCurrency('storefront/products/get_all_products', $variables);

            $products = collect($response['data']['products']['edges'] ?? [])
                ->map(fn($edge) => ProductDTO::fromShopifyResponse($edge['node']));

            // Apply accurate price sorting if PRICE sortKey is used
            // Shopify's API sorts by base price, but we need to sort by actual min variant price
            if (isset($filters['sortKey']) && strtoupper($filters['sortKey']) === 'PRICE') {
                $products = $products->sort(function ($a, $b) use ($filters) {
                    $minPriceA = collect($a->variants)->min(fn($v) => (float) $v->price);
                    $minPriceB = collect($b->variants)->min(fn($v) => (float) $v->price);
                    
                    $result = $minPriceA <=> $minPriceB;
                    
                    // Apply reverse if needed
                    return ($filters['reverse'] ?? false) ? -$result : $result;
                })->values();
            }

            $pageInfo = $response['data']['products']['pageInfo'] ?? [];

            $this->logPerformanceEnd('searchProducts', [
                'query' => $query,
                'count' => $products->count(),
                'has_next_page' => $pageInfo['hasNextPage'] ?? false,
            ]);

            return [
                'items' => $products,
                'pagination' => [
                    'has_more' => $pageInfo['hasNextPage'] ?? false,
                    'next_cursor' => $pageInfo['endCursor'] ?? null,
                ],
            ];
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to search products', $e, [
                'query' => $query,
                'limit' => $limit,
                'cursor' => $cursor,
            ]);
            throw $e;
        }
    }

    /**
     * Get featured products by tag with caching
     *
     * @param string $tag Tag to filter by
     * @param int $limit Number of products to fetch
     * @return Collection Collection of ProductDTO instances
     */
    public function getFeaturedProducts(string $tag = 'featured', int $limit = 10): Collection
    {
        try {
            $this->logPerformanceStart('getFeaturedProducts');

            $cacheKey = $this->cacheStrategy->getCacheKey('product.featured', [
                'tag' => $tag,
                'limit' => $limit,
                'currency' => $this->getCurrencyCountryCode(),
            ]);

            $products = $this->cacheWithFallback(
                $cacheKey,
                900, // 15 minutes
                fn() => $this->fetchFeaturedProducts($tag, $limit),
                ['products', 'featured']
            );

            $this->logPerformanceEnd('getFeaturedProducts', [
                'tag' => $tag,
                'count' => $products->count(),
            ]);

            return $products;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch featured products', $e, [
                'tag' => $tag,
                'limit' => $limit,
            ]);
            throw $e;
        }
    }

    /**
     * Get related products by Shopify product ID.
     *
     * Related products are resolved in priority order:
     * Shopify recommendations, same collection, matching tags/type, then latest products.
     *
     * @param string $productId Shopify product ID, numeric or gid format
     * @param int $limit Number of products to fetch
     * @return Collection Collection of ProductDTO instances
     */
    public function getRelatedProducts(string $productId, int $limit = 8): Collection
    {
        $limit = max(1, min($limit, 20));
        $normalizedProductId = $this->normalizeProductId($productId);

        try {
            $this->logPerformanceStart('getRelatedProducts');

            $cacheKey = $this->cacheStrategy->getCacheKey('product.related', [
                'product_id' => $normalizedProductId,
                'limit' => $limit,
                'currency' => $this->getCurrencyCountryCode(),
            ]);

            $products = $this->cacheWithFallback(
                $cacheKey,
                900,
                fn() => $this->fetchRelatedProducts($normalizedProductId, $limit),
                ['products', 'related']
            );

            $this->logPerformanceEnd('getRelatedProducts', [
                'product_id' => $normalizedProductId,
                'count' => $products->count(),
            ]);

            return $products;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch related products', $e, [
                'product_id' => $normalizedProductId,
                'limit' => $limit,
            ]);
            throw $e;
        }
    }

    /**
     * Fetch related products from Shopify with fallback priority.
     *
     * @param string $productId Normalized Shopify product gid
     * @param int $limit Number of products to fetch
     * @return Collection
     */
    private function fetchRelatedProducts(string $productId, int $limit): Collection
    {
        $products = $this->fetchShopifyRecommendations($productId, $limit);

        if ($products->count() >= $limit) {
            return $products->take($limit)->values();
        }

        $context = $this->fetchRelatedProductContext($productId);

        $products = $this->mergeRelatedProducts(
            $products,
            $this->fetchProductsFromSameCollection($context, $productId, $limit),
            $productId,
            $limit
        );

        if ($products->count() >= $limit) {
            return $products->take($limit)->values();
        }

        $products = $this->mergeRelatedProducts(
            $products,
            $this->fetchProductsByMatchingAttributes($context, $productId, $limit),
            $productId,
            $limit
        );

        if ($products->count() >= $limit) {
            return $products->take($limit)->values();
        }

        return $this->mergeRelatedProducts(
            $products,
            $this->fetchLatestRelatedProducts($productId, $limit),
            $productId,
            $limit
        )->take($limit)->values();
    }

    /**
     * Fetch Shopify product recommendations.
     *
     * @param string $productId Normalized Shopify product gid
     * @param int $limit Number of products to fetch
     * @return Collection
     */
    private function fetchShopifyRecommendations(string $productId, int $limit): Collection
    {
        try {
            $response = $this->storefrontClient->queryWithCurrency(
                'storefront/products/get_related_product_recommendations',
                [
                    'productId' => $productId,
                    'country' => $this->getCurrencyCountryCode(),
                ]
            );

            return collect($response['data']['productRecommendations'] ?? [])
                ->map(fn($product) => ProductDTO::fromShopifyResponse($product))
                ->reject(fn(ProductDTO $product) => $this->isSameProduct($product->id, $productId))
                ->take($limit)
                ->values();
        } catch (\Throwable $e) {
            $this->logWarning('Shopify recommendations failed; falling back', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Fetch the source product context for fallback matching.
     *
     * @param string $productId Normalized Shopify product gid
     * @return array<string, mixed>
     */
    private function fetchRelatedProductContext(string $productId): array
    {
        try {
            $response = $this->storefrontClient->queryWithCurrency(
                'storefront/products/get_related_product_context',
                [
                    'productId' => $productId,
                    'country' => $this->getCurrencyCountryCode(),
                ]
            );

            return $response['data']['node'] ?? [];
        } catch (\Throwable $e) {
            $this->logWarning('Related product context failed; continuing fallbacks', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Fetch products from the first shared collection.
     *
     * @param array<string, mixed> $context Source product context
     * @param string $productId Normalized Shopify product gid
     * @param int $limit Number of products to fetch
     * @return Collection
     */
    private function fetchProductsFromSameCollection(array $context, string $productId, int $limit): Collection
    {
        $collections = $context['collections']['edges'] ?? [];
        $collection = collect($collections)->first(fn($edge) => !empty($edge['node']['handle']));

        if (empty($collection['node']['handle'])) {
            return collect();
        }

        try {
            $result = $this->getCollectionProducts(
                $collection['node']['handle'],
                $limit + 1,
                null,
                'COLLECTION_DEFAULT',
                false
            );

            return $this->filterCurrentProduct($result['items'], $productId)->take($limit)->values();
        } catch (\Throwable $e) {
            $this->logWarning('Collection fallback failed; continuing fallbacks', [
                'product_id' => $productId,
                'collection_handle' => $collection['node']['handle'],
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Fetch products with matching tags or product type.
     *
     * @param array<string, mixed> $context Source product context
     * @param string $productId Normalized Shopify product gid
     * @param int $limit Number of products to fetch
     * @return Collection
     */
    private function fetchProductsByMatchingAttributes(array $context, string $productId, int $limit): Collection
    {
        $queries = collect($context['tags'] ?? [])
            ->filter()
            ->take(5)
            ->map(fn(string $tag) => 'tag:' . $this->quoteShopifySearchValue($tag));

        if (!empty($context['productType'])) {
            $queries->push('product_type:' . $this->quoteShopifySearchValue($context['productType']));
        }

        if ($queries->isEmpty()) {
            return collect();
        }

        try {
            $result = $this->getAllProducts($limit + 1, null, [
                'sortKey' => 'RELEVANCE',
                'reverse' => false,
                'query' => $queries->implode(' OR '),
            ]);

            return $this->filterCurrentProduct($result['items'], $productId)->take($limit)->values();
        } catch (\Throwable $e) {
            $this->logWarning('Attribute fallback failed; continuing fallbacks', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Fetch latest products as the final fallback.
     *
     * @param string $productId Normalized Shopify product gid
     * @param int $limit Number of products to fetch
     * @return Collection
     */
    private function fetchLatestRelatedProducts(string $productId, int $limit): Collection
    {
        try {
            $result = $this->getAllProducts($limit + 1, null, [
                'sortKey' => 'CREATED_AT',
                'reverse' => true,
                'query' => null,
            ]);

            return $this->filterCurrentProduct($result['items'], $productId)->take($limit)->values();
        } catch (\Throwable $e) {
            $this->logWarning('Latest products fallback failed', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Merge product candidates without duplicates or the requested product.
     *
     * @param Collection $current
     * @param Collection $candidates
     * @param string $productId Normalized Shopify product gid
     * @param int $limit Number of products to fetch
     * @return Collection
     */
    private function mergeRelatedProducts(
        Collection $current,
        Collection $candidates,
        string $productId,
        int $limit
    ): Collection {
        $seen = $current
            ->map(fn(ProductDTO $product) => $this->extractProductNumericId($product->id))
            ->filter()
            ->flip();

        foreach ($candidates as $candidate) {
            if (!$candidate instanceof ProductDTO) {
                continue;
            }

            $numericId = $this->extractProductNumericId($candidate->id);

            if ($this->isSameProduct($candidate->id, $productId) || $seen->has($numericId)) {
                continue;
            }

            $current->push($candidate);
            $seen->put($numericId, true);

            if ($current->count() >= $limit) {
                break;
            }
        }

        return $current->values();
    }

    /**
     * Remove the requested product from a collection.
     *
     * @param Collection $products
     * @param string $productId Normalized Shopify product gid
     * @return Collection
     */
    private function filterCurrentProduct(Collection $products, string $productId): Collection
    {
        return $products
            ->reject(fn(ProductDTO $product) => $this->isSameProduct($product->id, $productId))
            ->values();
    }

    /**
     * Normalize numeric Shopify product IDs to gid format.
     *
     * @param string $productId
     * @return string
     */
    private function normalizeProductId(string $productId): string
    {
        $productId = trim($productId);

        if (preg_match('/^\d+$/', $productId) === 1) {
            return "gid://shopify/Product/{$productId}";
        }

        return $productId;
    }

    /**
     * Compare Shopify product IDs across numeric and gid formats.
     *
     * @param string $first
     * @param string $second
     * @return bool
     */
    private function isSameProduct(string $first, string $second): bool
    {
        return $this->extractProductNumericId($first) === $this->extractProductNumericId($second);
    }

    /**
     * Extract numeric product ID from a Shopify product ID.
     *
     * @param string $productId
     * @return string
     */
    private function extractProductNumericId(string $productId): string
    {
        if (preg_match('/(\d+)(?:\?.*)?$/', $productId, $matches) === 1) {
            return $matches[1];
        }

        return $productId;
    }

    /**
     * Quote a Shopify search query value.
     *
     * @param string $value
     * @return string
     */
    private function quoteShopifySearchValue(string $value): string
    {
        return '"' . addcslashes($value, "\\\"") . '"';
    }

    /**
     * Fetch featured products from Shopify API
     *
     * @param string $tag Tag to filter by
     * @param int $limit Number of products to fetch
     * @return Collection
     */
    private function fetchFeaturedProducts(string $tag, int $limit): Collection
    {
        $variables = [
            'tag' => "tag:{$tag}",
            'limit' => $limit,
            'after' => null,
            'country' => $this->getCurrencyCountryCode(),
        ];

        $response = $this->storefrontClient->queryWithCurrency('storefront/product/products_featured', $variables);

        return collect($response['data']['products']['edges'] ?? [])
            ->map(fn($edge) => ProductDTO::fromShopifyResponse($edge['node']));
    }

    /**
     * Get all collections with caching
     *
     * @param int $limit Number of collections to fetch
     * @param string|null $cursor Pagination cursor
     * @return array ['items' => Collection, 'pagination' => array]
     */
    public function getCollections(int $limit = 50, ?string $cursor = null): array
    {
        try {
            $this->logPerformanceStart('getCollections');

            $cacheKey = $this->cacheStrategy->getCacheKey('collection.list', [
                'limit' => $limit,
                'cursor' => $cursor,
            ]);

            $result = $this->cacheWithFallback(
                $cacheKey,
                1800, // 30 minutes
                fn() => $this->fetchCollections($limit, $cursor),
                ['collections']
            );

            $this->logPerformanceEnd('getCollections', [
                'count' => $result['items']->count(),
                'has_next_page' => $result['pagination']['hasNextPage'] ?? false,
            ]);

            return $result;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch collections', $e, [
                'limit' => $limit,
                'cursor' => $cursor,
            ]);
            throw $e;
        }
    }

    /**
     * Fetch collections from Shopify API
     *
     * @param int $limit Number of collections to fetch
     * @param string|null $cursor Pagination cursor
     * @return array
     */
    private function fetchCollections(int $limit, ?string $cursor): array
    {
        $variables = [
            'limit' => $limit,
            'after' => $cursor,
        ];

        $response = $this->storefrontClient->query('storefront/collection/collections_list', $variables);

        $collections = collect($response['data']['collections']['edges'] ?? [])
            ->map(function ($edge) {
                $node = $edge['node'];
                // Count products from the products connection
                $productsCount = count($node['products']['edges'] ?? []);
                
                return CollectionDTO::fromShopifyResponse([
                    'id' => $node['id'],
                    'title' => $node['title'],
                    'handle' => $node['handle'],
                    'description' => $node['description'] ?? null,
                    'image' => $node['image'] ?? null,
                    'productsCount' => $productsCount,
                    'updatedAt' => $node['updatedAt'] ?? null,
                ]);
            });

        $pageInfo = $response['data']['collections']['pageInfo'] ?? [];

        return [
            'items' => $collections,
            'pagination' => [
                'hasNextPage' => $pageInfo['hasNextPage'] ?? false,
                'hasPreviousPage' => $pageInfo['hasPreviousPage'] ?? false,
                'startCursor' => $pageInfo['startCursor'] ?? null,
                'endCursor' => $pageInfo['endCursor'] ?? null,
            ],
        ];
    }

    /**
     * Get products by collection with pagination
     *
     * @param string $handle Collection handle
     * @param int $limit Number of products to fetch
     * @param string|null $cursor Pagination cursor
     * @param string $sortKey Sort key (TITLE, PRICE, BEST_SELLING, etc.)
     * @param bool $reverse Reverse sort order
     * @return array ['items' => Collection, 'pagination' => array, 'collection' => CollectionDTO]
     */
    public function getCollectionProducts(
        string $handle,
        int $limit = 20,
        ?string $cursor = null,
        string $sortKey = 'COLLECTION_DEFAULT',
        bool $reverse = false
    ): array {
        try {
            $this->logPerformanceStart('getCollectionProducts');

            $variables = [
                'handle' => $handle,
                'limit' => $limit,
                'after' => $cursor,
                'sortKey' => $sortKey,
                'reverse' => $reverse,
                'country' => $this->getCurrencyCountryCode(),
            ];

            $response = $this->storefrontClient->queryWithCurrency('storefront/collection/collection_products', $variables);

            if (empty($response['data']['collectionByHandle'])) {
                throw new \App\Exceptions\ShopifyNotFoundException("Collection not found: {$handle}");
            }

            $collectionData = $response['data']['collectionByHandle'];

            // Create collection DTO
            $collection = CollectionDTO::fromShopifyResponse([
                'id' => $collectionData['id'],
                'title' => $collectionData['title'],
                'handle' => $collectionData['handle'],
                'description' => $collectionData['description'] ?? null,
                'image' => $collectionData['image'] ?? null,
                'productsCount' => count($collectionData['products']['edges'] ?? []),
                'updatedAt' => $collectionData['updatedAt'] ?? null,
            ]);

            // Map products
            $products = collect($collectionData['products']['edges'] ?? [])
                ->map(fn($edge) => ProductDTO::fromShopifyResponse($edge['node']));

            $pageInfo = $collectionData['products']['pageInfo'] ?? [];

            $this->logPerformanceEnd('getCollectionProducts', [
                'handle' => $handle,
                'count' => $products->count(),
                'has_next_page' => $pageInfo['hasNextPage'] ?? false,
            ]);

            return [
                'items' => $products,
                'collection' => $collection,
                'pagination' => [
                    'hasNextPage' => $pageInfo['hasNextPage'] ?? false,
                    'hasPreviousPage' => $pageInfo['hasPreviousPage'] ?? false,
                    'startCursor' => $pageInfo['startCursor'] ?? null,
                    'endCursor' => $pageInfo['endCursor'] ?? null,
                ],
            ];
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch collection products', $e, [
                'handle' => $handle,
                'limit' => $limit,
                'cursor' => $cursor,
            ]);
            throw $e;
        }
    }
}
