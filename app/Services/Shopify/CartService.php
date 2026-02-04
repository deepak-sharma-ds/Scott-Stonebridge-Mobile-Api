<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use App\Contracts\Shopify\CartServiceInterface;
use App\Contracts\Shopify\ShopifyAdapterInterface;
use App\DTOs\Shopify\CartDTO;
use App\Services\GraphQL\GraphQLLoaderService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CartService implements CartServiceInterface
{
    private const CART_SECRET_CACHE_PREFIX = 'cart_secret_';

    private const CART_SECRET_TTL = 7 * 24 * 60; // 7 days in minutes

    public function __construct(
        private readonly ShopifyAdapterInterface $adapter,
        private readonly GraphQLLoaderService $queryLoader
    ) {}

    public function createGuestCart(
        array $lineItems = [],
        ?string $countryCode = null
    ): CartDTO {
        $query = $this->queryLoader->load('storefront/cart/create_cart');

        $input = [
            'lines' => $lineItems,
        ];

        // Add buyer identity for currency context
        if ($countryCode) {
            $input['buyerIdentity'] = [
                'countryCode' => $countryCode,
            ];
        }

        $response = $this->adapter->storefrontQuery($query, ['input' => $input]);

        $cart = $response['cartCreate']['cart'];

        // Extract and cache the cart secret (CRITICAL for security)
        $cartId = $cart['id'];
        $secret = $this->extractSecretFromCartId($cartId);

        $this->cacheCartSecret($cartId, $secret);

        Log::channel('shopify')->info('Guest cart created', [
            'cart_id' => $cartId,
            'country' => $countryCode,
        ]);

        return CartDTO::fromShopifyCart($cart);
    }

    public function getCart(string $cartId): ?CartDTO
    {
        $query = $this->queryLoader->load('storefront/cart/get_cart');

        $secret = $this->getCartSecret($cartId);
        if (! $secret) {
            Log::warning('Cart secret not found', ['cart_id' => $cartId]);

            return null;
        }

        $fullCartId = $this->buildFullCartId($cartId, $secret);

        $response = $this->adapter->storefrontQuery($query, ['cartId' => $fullCartId]);

        if (! isset($response['cart'])) {
            return null;
        }

        return CartDTO::fromShopifyCart($response['cart']);
    }

    public function addCartLines(string $cartId, array $lineItems): CartDTO
    {
        $query = $this->queryLoader->load('storefront/cart/add_cart_lines');

        $secret = $this->getCartSecret($cartId);
        $fullCartId = $this->buildFullCartId($cartId, $secret);

        $response = $this->adapter->storefrontQuery($query, [
            'cartId' => $fullCartId,
            'lines' => $lineItems,
        ]);

        return CartDTO::fromShopifyCart($response['cartLinesAdd']['cart']);
    }

    public function updateCartLines(string $cartId, array $updates): CartDTO
    {
        $query = $this->queryLoader->load('storefront/cart/update_cart_lines');

        $secret = $this->getCartSecret($cartId);
        $fullCartId = $this->buildFullCartId($cartId, $secret);

        $response = $this->adapter->storefrontQuery($query, [
            'cartId' => $fullCartId,
            'lines' => $updates,
        ]);

        return CartDTO::fromShopifyCart($response['cartLinesUpdate']['cart']);
    }

    public function removeCartLines(string $cartId, array $lineIds): CartDTO
    {
        $query = $this->queryLoader->load('storefront/cart/remove_cart_lines');

        $secret = $this->getCartSecret($cartId);
        $fullCartId = $this->buildFullCartId($cartId, $secret);

        $response = $this->adapter->storefrontQuery($query, [
            'cartId' => $fullCartId,
            'lineIds' => $lineIds,
        ]);

        return CartDTO::fromShopifyCart($response['cartLinesRemove']['cart']);
    }

    public function updateBuyerIdentity(
        string $cartId,
        ?string $email = null,
        ?string $phone = null,
        ?string $countryCode = null,
        ?string $customerAccessToken = null
    ): CartDTO {
        $query = $this->queryLoader->load('storefront/cart/update_buyer_identity');

        $secret = $this->getCartSecret($cartId);
        $fullCartId = $this->buildFullCartId($cartId, $secret);

        $buyerIdentity = array_filter([
            'email' => $email,
            'phone' => $phone,
            'countryCode' => $countryCode,
            'customerAccessToken' => $customerAccessToken,
        ]);

        $response = $this->adapter->storefrontQuery($query, [
            'cartId' => $fullCartId,
            'buyerIdentity' => $buyerIdentity,
        ]);

        return CartDTO::fromShopifyCart($response['cartBuyerIdentityUpdate']['cart']);
    }

    public function getCheckoutUrl(string $cartId): string
    {
        $cart = $this->getCart($cartId);

        return $cart?->checkoutUrl ?? '';
    }

    public function applyDiscountCode(string $cartId, string $discountCode): CartDTO
    {
        $query = $this->queryLoader->load('storefront/cart/apply_discount_codes');

        $secret = $this->getCartSecret($cartId);
        $fullCartId = $this->buildFullCartId($cartId, $secret);

        $response = $this->adapter->storefrontQuery($query, [
            'cartId' => $fullCartId,
            'discountCodes' => [$discountCode],
        ]);

        return CartDTO::fromShopifyCart($response['cartDiscountCodesUpdate']['cart']);
    }

    private function extractSecretFromCartId(string $cartId): string
    {
        // Cart ID format: "gid://shopify/Cart/{token}?key={secret}"
        parse_str(parse_url($cartId, PHP_URL_QUERY) ?? '', $params);

        return $params['key'] ?? '';
    }

    private function buildFullCartId(string $token, string $secret): string
    {
        return "{$token}?key={$secret}";
    }

    private function cacheCartSecret(string $cartId, string $secret): void
    {
        $cacheKey = self::CART_SECRET_CACHE_PREFIX.md5($cartId);
        Cache::put($cacheKey, $secret, now()->addMinutes(self::CART_SECRET_TTL));
    }

    private function getCartSecret(string $cartId): ?string
    {
        $cacheKey = self::CART_SECRET_CACHE_PREFIX.md5($cartId);

        return Cache::get($cacheKey);
    }
}
