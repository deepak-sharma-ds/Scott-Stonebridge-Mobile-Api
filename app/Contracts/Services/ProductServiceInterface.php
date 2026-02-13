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
     * @return Collection Collection of ProductDTO instances
     */
    public function getAllProducts(int $limit, ?string $cursor, array $filters): Collection;

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
     * @return Collection Collection of ProductDTO instances
     */
    public function searchProducts(string $query, int $limit, ?string $cursor): Collection;

    /**
     * Get featured products by tag
     *
     * @param string $tag Tag to filter by
     * @param int $limit Number of products to fetch
     * @return Collection Collection of ProductDTO instances
     */
    public function getFeaturedProducts(string $tag, int $limit): Collection;
}
