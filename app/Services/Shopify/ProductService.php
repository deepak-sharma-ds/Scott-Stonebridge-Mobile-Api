<?php

namespace App\Services\Shopify;

use App\Contracts\Services\ProductServiceInterface;
use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\DTOs\Product\ProductDTO;
use App\Services\Base\BaseService;
use Illuminate\Support\Collection;

class ProductService extends BaseService implements ProductServiceInterface
{
    public function __construct(
        protected StorefrontApiClientInterface $storefrontClient
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
     * Get featured products by tag
     *
     * @param string $tag Tag to filter by
     * @param int $limit Number of products to fetch
     * @return Collection Collection of ProductDTO instances
     */
    public function getFeaturedProducts(string $tag, int $limit): Collection
    {
        try {
            $this->logPerformanceStart('getFeaturedProducts');

            $variables = [
                'limit' => $limit,
                'query' => "tag:{$tag}",
                'country' => $this->getCurrencyCountryCode(),
            ];

            $response = $this->storefrontClient->queryWithCurrency('storefront/products/get_featured_products', $variables);

            $products = collect($response['data']['products']['edges'] ?? [])
                ->map(fn($edge) => ProductDTO::fromShopifyResponse($edge['node']));

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
}
