<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\Services\AI\ShopifyContextServiceInterface;
use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\DTOs\Chat\ChatContextDTO;
use App\DTOs\Chat\IntentDTO;
use App\Services\Base\BaseService;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Resolves any Shopify data the AI needs to answer the current turn but which
 * the frontend did not include in its context payload. Calls the existing
 * Storefront / Admin GraphQL clients — never the OpenAI API — and caches
 * results briefly under `ai:ctx:*` to limit Shopify API load.
 *
 * The output is intentionally compact: only fields that will actually be
 * injected into the prompt (titles, prices, summarized policies, etc.).
 */
class ShopifyContextService extends BaseService implements ShopifyContextServiceInterface
{
    private const POLICY_SUMMARY_MAX_CHARS = 600;

    public function __construct(
        private readonly StorefrontApiClientInterface $storefront,
    ) {
        parent::__construct();
    }

    public function resolve(ChatContextDTO $context, IntentDTO $intent, ?string $accessToken = null): array
    {
        $shop = $context->shopDomain ?? config('shopify.store_domain');
        $ttl = (int) config('chatbot.context.cache_ttl', 180);
        $prefix = (string) config('chatbot.context.cache_prefix', 'ai:ctx');

        $resolved = [
            'shop' => $shop,
            'page_type' => $context->pageType,
            'locale' => $context->locale,
            'currency' => $context->currency,
        ];

        try {
            switch ($intent->name) {
                case IntentDTO::INTENT_PRODUCT_SUPPORT:
                    $resolved['product'] = $this->resolveProduct($context, (string) ($shop ?? 'default'), $prefix, $ttl);
                    break;

                case IntentDTO::INTENT_REFUND_POLICY:
                case IntentDTO::INTENT_SHIPPING_QUESTION:
                    $resolved['policies'] = $this->resolvePolicies((string) ($shop ?? 'default'), $prefix, $ttl);
                    break;

                case IntentDTO::INTENT_CART_HELP:
                    $resolved['cart'] = $context->cart?->toArray();
                    break;

                case IntentDTO::INTENT_RECOMMENDATION:
                    // Product list comes from ProductRecommendationService — nothing extra here.
                    break;

                case IntentDTO::INTENT_ORDER_TRACKING:
                    $resolved['customer_logged_in'] = $context->customer?->loggedIn ?? false;
                    // Live order resolution requires a customer access token — handled
                    // by the orchestrator when one is available.
                    break;
            }
        } catch (Throwable $e) {
            $this->logWarning('Context resolution partial failure', [
                'intent' => $intent->name,
                'error' => $e->getMessage(),
            ], 'ai');
        }

        return array_filter($resolved, fn ($v) => $v !== null && $v !== []);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveProduct(ChatContextDTO $context, string $shop, string $prefix, int $ttl): ?array
    {
        if ($context->product === null || $context->product->isEmpty()) {
            return null;
        }

        $handle = $context->product->handle;
        if ($handle === null || $handle === '') {
            // No handle to look up — return the frontend snapshot as-is.
            return [
                'id' => $context->product->id,
                'title' => $context->product->title,
                'vendor' => $context->product->vendor,
                'price' => $context->product->price,
                'tags' => $context->product->tags,
            ];
        }

        $key = sprintf('%s:%s:product:%s', $prefix, $shop, $handle);

        return Cache::remember($key, $ttl, function () use ($handle, $context): ?array {
            try {
                $response = $this->storefront->query('storefront/products/get_product_details', [
                    'handle' => $handle,
                    'country' => $this->countryFromCurrency($context->currency),
                ]);

                $node = $response['data']['productByHandle'] ?? null;
                if (! is_array($node)) {
                    return null;
                }

                $firstVariant = $node['variants']['edges'][0]['node'] ?? null;

                return [
                    'id' => $node['id'] ?? null,
                    'title' => $node['title'] ?? null,
                    'handle' => $node['handle'] ?? null,
                    'vendor' => $node['vendor'] ?? null,
                    'product_type' => $node['productType'] ?? null,
                    'tags' => $node['tags'] ?? [],
                    'price' => $firstVariant['price']['amount'] ?? null,
                    'currency' => $firstVariant['price']['currencyCode'] ?? null,
                    'available' => (bool) ($firstVariant['availableForSale'] ?? true),
                    'options' => array_map(
                        static fn (array $o): array => [
                            'name' => $o['name'] ?? null,
                            'values' => $o['values'] ?? [],
                        ],
                        $node['options'] ?? [],
                    ),
                    // Strip HTML; cap to keep token usage sane.
                    'description' => mb_strimwidth(strip_tags((string) ($node['description'] ?? '')), 0, 400, '…'),
                ];
            } catch (Throwable $e) {
                $this->logWarning('Storefront product fetch failed', [
                    'handle' => $handle,
                    'error' => $e->getMessage(),
                ], 'ai');

                return [
                    'id' => $context->product?->id,
                    'title' => $context->product?->title,
                    'handle' => $handle,
                    'vendor' => $context->product?->vendor,
                ];
            }
        });
    }

    /**
     * @return array<string, string>
     */
    private function resolvePolicies(string $shop, string $prefix, int $ttl): array
    {
        $key = sprintf('%s:%s:policies', $prefix, $shop);

        return Cache::remember($key, $ttl, function (): array {
            try {
                $response = $this->storefront->query('storefront/policies/get_all_policies');
                $shopData = $response['data']['shop'] ?? [];

                return array_filter([
                    'refund' => $this->summarizePolicy($shopData['refundPolicy'] ?? null),
                    'shipping' => $this->summarizePolicy($shopData['shippingPolicy'] ?? null),
                    'privacy' => $this->summarizePolicy($shopData['privacyPolicy'] ?? null),
                    'terms' => $this->summarizePolicy($shopData['termsOfService'] ?? null),
                ], fn ($v) => $v !== null && $v !== '');
            } catch (Throwable $e) {
                $this->logWarning('Storefront policies fetch failed', [
                    'error' => $e->getMessage(),
                ], 'ai');

                return [];
            }
        });
    }

    /**
     * @param  array<string, mixed>|null  $policy
     */
    private function summarizePolicy(?array $policy): ?string
    {
        if (! is_array($policy) || empty($policy['body'])) {
            return null;
        }

        $body = strip_tags((string) $policy['body']);
        $body = trim((string) preg_replace('/\s+/u', ' ', $body));

        return mb_strimwidth($body, 0, self::POLICY_SUMMARY_MAX_CHARS, '…');
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
