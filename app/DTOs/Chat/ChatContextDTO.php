<?php

declare(strict_types=1);

namespace App\DTOs\Chat;

use App\DTOs\Base\BaseDTO;

/**
 * Aggregate of every storefront-side signal the frontend collects before a
 * message is sent. The context resolver decides which additional Shopify
 * GraphQL calls are necessary to enrich missing fields.
 */
class ChatContextDTO extends BaseDTO
{
    /**
     * @param  list<string>  $recentlyViewed
     */
    public function __construct(
        public readonly string $pageType,
        public readonly ?ProductContextDTO $product,
        public readonly ?CartContextDTO $cart,
        public readonly ?CustomerContextDTO $customer,
        public readonly array $recentlyViewed,
        public readonly ?string $shopDomain,
        public readonly ?string $currency,
        public readonly ?string $locale,
    ) {
        $this->validate();
    }

    protected function validate(): void
    {
        $allowed = ['home', 'product', 'collection', 'cart', 'search', 'account', 'blog', 'page', 'unknown'];
        $this->validateInArray($this->pageType, $allowed, 'page_type');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            pageType: (string) ($data['page_type'] ?? $data['pageType'] ?? 'unknown'),
            product: ! empty($data['product']) ? ProductContextDTO::fromArray((array) $data['product']) : null,
            cart: ! empty($data['cart']) ? CartContextDTO::fromArray((array) $data['cart']) : null,
            customer: ! empty($data['customer']) ? CustomerContextDTO::fromArray((array) $data['customer']) : null,
            recentlyViewed: array_values(array_filter((array) ($data['recently_viewed'] ?? $data['recentlyViewed'] ?? []), 'is_string')),
            shopDomain: isset($data['shop_domain']) ? (string) $data['shop_domain'] : (isset($data['shopDomain']) ? (string) $data['shopDomain'] : null),
            currency: isset($data['currency']) ? (string) $data['currency'] : null,
            locale: isset($data['locale']) ? (string) $data['locale'] : null,
        );
    }
}
