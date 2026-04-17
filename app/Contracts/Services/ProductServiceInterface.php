<?php

namespace App\Contracts\Services;

use App\DTOs\Product\ProductDTO;
use Illuminate\Support\Collection;

interface ProductServiceInterface
{
    /**
     * Get all products with pagination
     *
     * @param int $limit Number of products to fetch
     * @param string|null $cursor Pagination cursor
     * @param array $filters Additional filters
     * @return array ['items' => Collection, 'pagination' => array]
     */
    public function getAllProducts(int $limit, ?string $cursor, array $filters): array;

    /**
     * Get a single product by handle
     *
     * @param string $handle Product handle
     * @return ProductDTO
     */
    public function getProductByHandle(string $handle): ProductDTO;

    /**
     * Search products by query
     *
     * @param string $query Search query
     * @param int $limit Number of products to fetch
     * @param string|null $cursor Pagination cursor
     * @return array ['items' => Collection, 'pagination' => array]
     */
    public function searchProducts(string $query, int $limit, ?string $cursor): array;

    /**
     * Get featured products by tag
     *
     * @param string $tag Tag to filter by
     * @param int $limit Number of products to fetch
     * @return Collection Collection of ProductDTO instances
     */
    public function getFeaturedProducts(string $tag, int $limit): Collection;

    /**
     * Get all collections with caching
     *
     * @param int $limit Number of collections to fetch
     * @param string|null $cursor Pagination cursor
     * @return array ['items' => Collection, 'pagination' => array]
     */
    public function getCollections(int $limit = 50, ?string $cursor = null): array;

    /**
     * Get products by collection with pagination
     *
     * @param string $handle Collection handle
     * @param int $limit Number of products to fetch
     * @param string|null $cursor Pagination cursor
     * @param string $sortKey Sort key
     * @param bool $reverse Reverse sort order
     * @return array ['items' => Collection, 'pagination' => array, 'collection' => CollectionDTO]
     */
    public function getCollectionProducts(
        string $handle,
        int $limit = 20,
        ?string $cursor = null,
        string $sortKey = 'COLLECTION_DEFAULT',
        bool $reverse = false
    ): array;
}
