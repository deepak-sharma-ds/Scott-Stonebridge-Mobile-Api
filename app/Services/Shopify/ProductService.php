<?php

namespace App\Services\Shopify;

use App\Contracts\Services\ProductServiceInterface;
use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\DTOs\Product\ProductDTO;
use App\DTOs\Product\CollectionDTO;
use App\Services\Base\BaseService;
use App\Services\Cache\ShopifyCacheStrategy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ProductService extends BaseService implements ProductServiceInterface
{
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
    public function getAllProducts(int $limit, ?string $cursor, array $filters): Collection
    {
        try {
            $this->logPerformanceStart('getAllProducts');

            $variables = [
                'limit' => $limit,
                'after' => $cursor,
                'sortKey' => $filters['sortKey'] ?? 'TITLE',
                'reverse' => $filters['reverse'] ?? false,
                'query' => $filters['query'] ?? null,
                'country' => $this->getCurrencyCountryCode(),
            ];

            $response = $this->storefrontClient->queryWithCurrency('storefront/products/get_all_products', $variables);

            $products = collect($response['data']['products']['edges'] ?? [])
                ->map(fn($edge) => ProductDTO::fromShopifyResponse($edge['node']));

            $this->logPerformanceEnd('getAllProducts', [
                'count' => $products->count(),
                'has_next_page' => $response['data']['products']['pageInfo']['hasNextPage'] ?? false,
            ]);

            return $products;
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
     * @return Collection Collection of ProductDTO instances
     */
    public function searchProducts(string $query, int $limit, ?string $cursor): Collection
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

            $this->logPerformanceEnd('searchProducts', [
                'query' => $query,
                'count' => $products->count(),
            ]);

            return $products;
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

            $products = Cache::tags(['products', 'featured'])
                ->remember(
                    $cacheKey,
                    900, // 15 minutes
                    fn() => $this->fetchFeaturedProducts($tag, $limit)
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

            $result = Cache::tags(['collections'])
                ->remember(
                    $cacheKey,
                    1800, // 30 minutes
                    fn() => $this->fetchCollections($limit, $cursor)
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
