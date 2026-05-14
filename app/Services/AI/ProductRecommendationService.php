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

        $trimmedQuery = $this->buildShopifyQuery($query, $context);
        if ($trimmedQuery === '') {
            return [];
        }

        $cacheKey = sprintf('ai:rec:%s:%s:%d', $shop, md5(mb_strtolower($trimmedQuery)), $limit);

        try {
            return Cache::remember($cacheKey, 300, function () use ($trimmedQuery, $limit, $shop, $context): array {
                $response = $this->storefront->query('storefront/products/get_all_products', [
                    'limit' => $limit,
                    'sortKey' => 'RELEVANCE',
                    'reverse' => false,
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
                'error' => $e->getMessage(),
            ], 'ai');

            return [];
        }
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
            'can', 'you', 'something', 'similar', 'to', 'this', 'that',
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
