<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\Services\AI\ProductRecommendationServiceInterface;
use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\DTOs\Chat\ChatContextDTO;
use App\DTOs\Chat\ProductRecommendationDTO;
use App\Services\Base\BaseService;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Calls the Shopify Storefront API to find products matching the user's
 * recommendation query. Results are trimmed to ProductRecommendationDTO
 * shape so they are safe to inject into the AI prompt and to render as
 * frontend cards. The AI never invents products — only items returned here
 * may appear in the assistant's reply.
 */
class ProductRecommendationService extends BaseService implements ProductRecommendationServiceInterface
{
    public function __construct(
        private readonly StorefrontApiClientInterface $storefront,
    ) {
        parent::__construct();
    }

    public function search(string $query, ChatContextDTO $context, ?int $limit = null): array
    {
        $limit ??= (int) config('chatbot.recommendation.limit', 6);
        $shop = $context->shopDomain ?? (string) config('shopify.store_domain');

        // Detect sort intent BEFORE stop-word filtering. Phrases like
        // "top selling" / "newest" / "cheapest" are a SORT request, not a
        // search filter — Shopify Storefront exposes ProductSortKeys for
        // exactly this. The previous code force-used RELEVANCE and pushed
        // the sort-words into the query string, which matched ~nothing in
        // most stores.
        [$sortKey, $reverse, $stripped] = $this->detectSortIntent($query);

        $trimmedQuery = $this->buildShopifyQuery($stripped, $context);

        // When a sort key is requested, an empty filter is OK — Shopify
        // returns the top-N by that sort. When RELEVANCE is the only signal
        // and the filter is empty, fall back to BEST_SELLING so the user
        // always gets *something* back rather than an empty product list.
        if ($trimmedQuery === '' && $sortKey === 'RELEVANCE') {
            $sortKey = 'BEST_SELLING';
            $reverse = false;
        }

        $cacheKey = sprintf('ai:rec:%s:%s:%s:%d', $shop, $sortKey, md5(mb_strtolower($trimmedQuery)), $limit);

        try {
            return Cache::remember($cacheKey, 300, function () use ($trimmedQuery, $limit, $shop, $context, $sortKey, $reverse): array {
                $response = $this->storefront->query('storefront/products/get_all_products', [
                    'limit' => $limit,
                    'sortKey' => $sortKey,
                    'reverse' => $reverse,
                    'query' => $trimmedQuery,
                    'country' => $this->countryFromCurrency($context->currency),
                ]);

                $edges = $response['data']['products']['edges'] ?? [];
                if (! is_array($edges) || $edges === []) {
                    return [];
                }

                return array_values(array_map(
                    static fn (array $edge): ProductRecommendationDTO => ProductRecommendationDTO::fromShopifyNode(
                        (array) ($edge['node'] ?? []),
                        $shop,
                    ),
                    array_filter($edges, static fn ($edge): bool => is_array($edge) && ! empty($edge['node'])),
                ));
            });
        } catch (Throwable $e) {
            $this->logWarning('Product recommendation lookup failed', [
                'query' => $trimmedQuery,
                'sort' => $sortKey,
                'error' => $e->getMessage(),
            ], 'ai');

            return [];
        }
    }

    /**
     * Map natural-language sort phrases to Shopify ProductSortKeys.
     * Returns [sortKey, reverse, messageWithSortKeywordsStripped].
     *
     * @return array{0: string, 1: bool, 2: string}
     */
    private function detectSortIntent(string $message): array
    {
        $lower = mb_strtolower($message);

        // Best sellers: "top selling", "best selling", "popular", "bestseller", "trending", "hot".
        if (preg_match('/\b(top\s*sell\w*|best\s*sell\w*|bestseller\w*|popular|trending|hot\s+items?)\b/u', $lower)) {
            $stripped = (string) preg_replace('/\b(top|best|sell\w*|bestseller\w*|popular|trending|hot|items?)\b/u', '', $lower);

            return ['BEST_SELLING', false, $stripped];
        }

        // Newest: "newest", "latest", "new arrivals", "recent".
        if (preg_match('/\b(newest|latest|new\s+arrivals?|recently?\s+added|recent)\b/u', $lower)) {
            $stripped = (string) preg_replace('/\b(newest|latest|new|arrivals?|recently?|added|recent)\b/u', '', $lower);

            return ['CREATED_AT', true, $stripped];
        }

        // Cheapest: "cheap", "cheapest", "affordable", "lowest price", "under £N".
        if (preg_match('/\b(cheap\w*|affordable|lowest\s+price|under\s+[£$€]?\d+)\b/u', $lower)) {
            $stripped = (string) preg_replace('/\b(cheap\w*|affordable|lowest|price|under|[£$€]?\d+)\b/u', '', $lower);

            return ['PRICE', false, $stripped];
        }

        // Most expensive / premium / luxury.
        if (preg_match('/\b(premium|luxury|expensive|highest\s+price|high-?end)\b/u', $lower)) {
            $stripped = (string) preg_replace('/\b(premium|luxury|expensive|highest|price|high-?end)\b/u', '', $lower);

            return ['PRICE', true, $stripped];
        }

        return ['RELEVANCE', false, $message];
    }

    /**
     * Strip filler words and assemble a Shopify Storefront search query string.
     * Examples:
     *   "Suggest me gaming headphones"  -> "gaming headphones"
     *   "Best skincare under $50"       -> "skincare"
     *   "Looking for waterproof bags"   -> "waterproof bags"
     */
    private function buildShopifyQuery(string $userMessage, ChatContextDTO $context): string
    {
        $stop = [
            'recommend', 'recommendation', 'suggest', 'suggestion', 'please',
            'best', 'top', 'good', 'better', 'looking', 'for', 'show', 'me',
            'find', 'i', 'want', 'need', 'a', 'an', 'the', 'some', 'any',
            'can', 'you', 'your', 'something', 'similar', 'to', 'this', 'that',
            // Generic e-commerce filler — every Shopify item is a "product",
            // so the literal word matches almost nothing as a search filter.
            'product', 'products', 'item', 'items', 'thing', 'things',
            'are', 'is', 'what', 'which',
        ];

        $words = preg_split('/\s+/u', mb_strtolower(trim($userMessage))) ?: [];
        $kept = array_values(array_filter($words, static function (string $w) use ($stop): bool {
            $w = preg_replace('/[^\p{L}\p{N}\-]/u', '', $w) ?? $w;

            return $w !== '' && ! in_array($w, $stop, true);
        }));

        if ($kept === [] && $context->product?->title !== null) {
            // Fallback: recommend similar to the current product.
            $kept = preg_split('/\s+/u', mb_strtolower($context->product->title)) ?: [];
        }

        return implode(' ', array_slice($kept, 0, 6));
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
