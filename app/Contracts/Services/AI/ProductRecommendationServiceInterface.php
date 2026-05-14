<?php

declare(strict_types=1);

namespace App\Contracts\Services\AI;

use App\DTOs\Chat\ChatContextDTO;
use App\DTOs\Chat\ProductRecommendationDTO;

interface ProductRecommendationServiceInterface
{
    /**
     * Search the Shopify storefront for products matching the user's intent.
     * Returned products are SAFE to feed into the prompt — Shopify is the only
     * source of truth, the AI does not invent products.
     *
     * @return list<ProductRecommendationDTO>
     */
    public function search(string $query, ChatContextDTO $context, ?int $limit = null): array;
}
