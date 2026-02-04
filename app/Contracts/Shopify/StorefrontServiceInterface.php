<?php

declare(strict_types=1);

namespace App\Contracts\Shopify;

use App\DTOs\Shopify\ProductDTO;
use Illuminate\Support\Collection;

interface StorefrontServiceInterface
{
    /**
     * Get product by handle
     */
    public function getProductByHandle(
        string $handle,
        ?string $countryCode = null
    ): ?ProductDTO;
    
    /**
     * Get products with optional filtering
     */
    public function getProducts(
        int $limit = 20,
        ?string $cursor = null,
        ?string $collectionHandle = null,
        ?string $query = null,
        ?string $countryCode = null
    ): Collection;
    
    /**
     * Get collections
     */
    public function getCollections(
        int $limit = 20,
        ?string $cursor = null,
        ?string $countryCode = null
    ): Collection;
}
