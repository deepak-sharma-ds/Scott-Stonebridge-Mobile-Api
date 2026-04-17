<?php

namespace App\DTOs\Cart;

use App\DTOs\Base\BaseDTO;
use InvalidArgumentException;

/**
 * Cart Data Transfer Object
 * 
 * Represents a Shopify cart with typed properties and validation.
 * Carts contain line items and cost information for guest or authenticated users.
 * 
 * Requirements: 16.2, 16.6, 16.7
 */
class CartDTO extends BaseDTO
{
    public function __construct(
        public readonly string $id,
        public readonly array $lineItems,
        public readonly string $checkoutUrl,
        public readonly array $cost,
        public readonly ?array $buyerIdentity,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
        $this->validate();
    }

    /**
     * Validate the cart data.
     * 
     * @throws InvalidArgumentException
     */
    protected function validate(): void
    {
        $this->validateRequired($this->id, 'Cart ID');
    }

    /**
     * Create a CartDTO from Shopify API response data.
     * 
     * Transforms raw Shopify GraphQL cart response into a typed DTO instance.
     * Handles nested line items and cost information.
     * 
     * @param array $data Raw cart data from Shopify GraphQL response
     * @return self
     */
    public static function fromShopifyResponse(array $data): self
    {
        // Handle both edge/node structure and flat array structure for line items
        $lines = $data['lines']['edges'] ?? $data['lines'] ?? [];
        $lineItems = array_map(
            fn($item) => CartLineItemDTO::fromShopifyResponse($item['node'] ?? $item),
            $lines
        );

        // Build proper checkout URL with key from cart ID
        $checkoutUrl = self::buildCheckoutUrlFromCartId($data['id'], $data['checkoutUrl'] ?? '');

        return new self(
            id: $data['id'],
            lineItems: $lineItems,
            checkoutUrl: $checkoutUrl,
            cost: [
                'subtotal' => $data['cost']['subtotalAmount']['amount'] ?? '0.00',
                'total' => $data['cost']['totalAmount']['amount'] ?? '0.00',
                'currency' => $data['cost']['totalAmount']['currencyCode'] ?? 'GBP',
            ],
            buyerIdentity: $data['buyerIdentity'] ?? null,
            createdAt: $data['createdAt'] ?? now()->toIso8601String(),
            updatedAt: $data['updatedAt'] ?? now()->toIso8601String(),
        );
    }

    /**
     * Build checkout URL with authentication key from cart ID
     * 
     * Shopify cart IDs contain the cart token and optional key:
     * Format: gid://shopify/Cart/TOKEN?key=KEY
     * 
     * The checkout URL should be: https://shop.com/cart/c/TOKEN?key=ENCODED_KEY
     * 
     * @param string $cartId Full cart ID from Shopify
     * @param string $baseCheckoutUrl Base checkout URL from Shopify response
     * @return string Complete checkout URL with authentication key
     */
    private static function buildCheckoutUrlFromCartId(string $cartId, string $baseCheckoutUrl): string
    {
        // If the base checkout URL already has a key parameter, use it as-is
        if (str_contains($baseCheckoutUrl, '?key=')) {
            return $baseCheckoutUrl;
        }

        // Parse cart ID to extract token and key
        // Format: gid://shopify/Cart/hWN9zGWJF7tz56kV7ZaED7yg?key=f4e3e831adf36bc0f80ffd628939a18c
        if (preg_match('/gid:\/\/shopify\/Cart\/([^\?]+)(?:\?key=(.+))?/', $cartId, $matches)) {
            $cartToken = $matches[1];
            $cartKey = $matches[2] ?? null;

            if ($cartKey && $baseCheckoutUrl) {
                // Extract the shop domain from base checkout URL
                if (preg_match('/^(https?:\/\/[^\/]+)/', $baseCheckoutUrl, $urlMatches)) {
                    $shopDomain = $urlMatches[1];
                    
                    // Build the full checkout URL with the key
                    // The key needs to be properly encoded for the URL
                    return $shopDomain . '/cart/c/' . $cartToken . '?key=' . $cartKey;
                }
            }
        }

        // Fallback to base checkout URL if we can't parse the cart ID
        return $baseCheckoutUrl;
    }

    /**
     * Get the total number of items in the cart.
     * 
     * Sums the quantity of all line items.
     * 
     * @return int
     */
    public function getTotalItems(): int
    {
        return array_reduce(
            $this->lineItems,
            fn($sum, $item) => $sum + $item->quantity,
            0
        );
    }
}
