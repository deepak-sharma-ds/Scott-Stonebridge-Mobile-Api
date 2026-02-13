<?php

namespace App\Services\Shopify;

use App\Contracts\Services\CartServiceInterface;
use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\DTOs\Cart\CartDTO;
use App\Services\Base\BaseService;
use App\Services\GraphQL\GraphQLLoaderService;
use App\Exceptions\ShopifyApiException;
use App\Exceptions\ShopifyNotFoundException;

class CartService extends BaseService implements CartServiceInterface
{
    public function __construct(
        protected StorefrontApiClientInterface $storefrontClient,
        protected GraphQLLoaderService $graphQLLoader
    ) {
        parent::__construct();
    }

    /**
     * Create a new cart
     *
     * @param string|null $accessToken Optional customer access token
     * @return CartDTO
     */
    public function createCart(?string $accessToken = null): CartDTO
    {
        try {
            $this->logPerformanceStart('createCart');

            $query = $this->graphQLLoader->load('storefront/cart/create_cart');

            $input = [];
            
            if ($accessToken) {
                $input['buyerIdentity'] = [
                    'customerAccessToken' => $accessToken,
                ];
            }

            $variables = ['input' => $input];

            $response = $this->storefrontClient->query($query, $variables);

            if (!empty($response['data']['cartCreate']['userErrors'])) {
                $errors = $response['data']['cartCreate']['userErrors'];
                throw new ShopifyApiException('Failed to create cart: ' . json_encode($errors));
            }

            if (empty($response['data']['cartCreate']['cart'])) {
                throw new ShopifyApiException('Cart creation returned empty response');
            }

            $cart = CartDTO::fromShopifyResponse($response['data']['cartCreate']['cart']);

            $this->logPerformanceEnd('createCart', [
                'cart_id' => $cart->id,
                'has_customer' => $accessToken !== null,
            ]);

            return $cart;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to create cart', $e, [
                'has_access_token' => $accessToken !== null,
            ]);
            throw $e;
        }
    }

    /**
     * Get cart by ID
     *
     * @param string $cartId Cart identifier
     * @return CartDTO
     */
    public function getCart(string $cartId): CartDTO
    {
        try {
            $this->logPerformanceStart('getCart');

            $query = $this->graphQLLoader->load('storefront/cart/get_cart');

            $variables = ['cartId' => $cartId];

            $response = $this->storefrontClient->query($query, $variables);

            if (empty($response['data']['cart'])) {
                throw new ShopifyNotFoundException("Cart not found: {$cartId}");
            }

            $cart = CartDTO::fromShopifyResponse($response['data']['cart']);

            $this->logPerformanceEnd('getCart', [
                'cart_id' => $cartId,
                'line_items_count' => count($cart->lineItems),
            ]);

            return $cart;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch cart', $e, ['cart_id' => $cartId]);
            throw $e;
        }
    }

    /**
     * Add a line item to cart
     *
     * @param string $cartId Cart identifier
     * @param string $variantId Product variant ID
     * @param int $quantity Quantity to add
     * @return CartDTO
     */
    public function addLineItem(string $cartId, string $variantId, int $quantity): CartDTO
    {
        try {
            $this->logPerformanceStart('addLineItem');

            $query = $this->graphQLLoader->load('storefront/cart/add_line_item');

            $variables = [
                'cartId' => $cartId,
                'lines' => [
                    [
                        'merchandiseId' => $variantId,
                        'quantity' => $quantity,
                    ],
                ],
            ];

            $response = $this->storefrontClient->query($query, $variables);

            if (!empty($response['data']['cartLinesAdd']['userErrors'])) {
                $errors = $response['data']['cartLinesAdd']['userErrors'];
                throw new ShopifyApiException('Failed to add line item: ' . json_encode($errors));
            }

            if (empty($response['data']['cartLinesAdd']['cart'])) {
                throw new ShopifyApiException('Add line item returned empty response');
            }

            $cart = CartDTO::fromShopifyResponse($response['data']['cartLinesAdd']['cart']);

            $this->logPerformanceEnd('addLineItem', [
                'cart_id' => $cartId,
                'variant_id' => $variantId,
                'quantity' => $quantity,
            ]);

            return $cart;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to add line item', $e, [
                'cart_id' => $cartId,
                'variant_id' => $variantId,
                'quantity' => $quantity,
            ]);
            throw $e;
        }
    }

    /**
     * Update a line item quantity
     *
     * @param string $cartId Cart identifier
     * @param string $lineId Line item ID
     * @param int $quantity New quantity
     * @return CartDTO
     */
    public function updateLineItem(string $cartId, string $lineId, int $quantity): CartDTO
    {
        try {
            $this->logPerformanceStart('updateLineItem');

            $query = $this->graphQLLoader->load('storefront/cart/update_line_item');

            $variables = [
                'cartId' => $cartId,
                'lines' => [
                    [
                        'id' => $lineId,
                        'quantity' => $quantity,
                    ],
                ],
            ];

            $response = $this->storefrontClient->query($query, $variables);

            if (!empty($response['data']['cartLinesUpdate']['userErrors'])) {
                $errors = $response['data']['cartLinesUpdate']['userErrors'];
                throw new ShopifyApiException('Failed to update line item: ' . json_encode($errors));
            }

            if (empty($response['data']['cartLinesUpdate']['cart'])) {
                throw new ShopifyApiException('Update line item returned empty response');
            }

            $cart = CartDTO::fromShopifyResponse($response['data']['cartLinesUpdate']['cart']);

            $this->logPerformanceEnd('updateLineItem', [
                'cart_id' => $cartId,
                'line_id' => $lineId,
                'quantity' => $quantity,
            ]);

            return $cart;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to update line item', $e, [
                'cart_id' => $cartId,
                'line_id' => $lineId,
                'quantity' => $quantity,
            ]);
            throw $e;
        }
    }

    /**
     * Remove a line item from cart
     *
     * @param string $cartId Cart identifier
     * @param string $lineId Line item ID
     * @return CartDTO
     */
    public function removeLineItem(string $cartId, string $lineId): CartDTO
    {
        try {
            $this->logPerformanceStart('removeLineItem');

            $query = $this->graphQLLoader->load('storefront/cart/remove_line_item');

            $variables = [
                'cartId' => $cartId,
                'lineIds' => [$lineId],
            ];

            $response = $this->storefrontClient->query($query, $variables);

            if (!empty($response['data']['cartLinesRemove']['userErrors'])) {
                $errors = $response['data']['cartLinesRemove']['userErrors'];
                throw new ShopifyApiException('Failed to remove line item: ' . json_encode($errors));
            }

            if (empty($response['data']['cartLinesRemove']['cart'])) {
                throw new ShopifyApiException('Remove line item returned empty response');
            }

            $cart = CartDTO::fromShopifyResponse($response['data']['cartLinesRemove']['cart']);

            $this->logPerformanceEnd('removeLineItem', [
                'cart_id' => $cartId,
                'line_id' => $lineId,
            ]);

            return $cart;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to remove line item', $e, [
                'cart_id' => $cartId,
                'line_id' => $lineId,
            ]);
            throw $e;
        }
    }

    /**
     * Associate cart with customer
     *
     * @param string $cartId Cart identifier
     * @param string $accessToken Customer access token
     * @return CartDTO
     */
    public function associateCustomer(string $cartId, string $accessToken): CartDTO
    {
        try {
            $this->logPerformanceStart('associateCustomer');

            $query = $this->graphQLLoader->load('storefront/cart/associate_customer');

            $variables = [
                'cartId' => $cartId,
                'buyerIdentity' => [
                    'customerAccessToken' => $accessToken,
                ],
            ];

            $response = $this->storefrontClient->query($query, $variables);

            if (!empty($response['data']['cartBuyerIdentityUpdate']['userErrors'])) {
                $errors = $response['data']['cartBuyerIdentityUpdate']['userErrors'];
                throw new ShopifyApiException('Failed to associate customer: ' . json_encode($errors));
            }

            if (empty($response['data']['cartBuyerIdentityUpdate']['cart'])) {
                throw new ShopifyApiException('Associate customer returned empty response');
            }

            $cart = CartDTO::fromShopifyResponse($response['data']['cartBuyerIdentityUpdate']['cart']);

            $this->logPerformanceEnd('associateCustomer', [
                'cart_id' => $cartId,
            ]);

            return $cart;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to associate customer with cart', $e, [
                'cart_id' => $cartId,
            ]);
            throw $e;
        }
    }
}
