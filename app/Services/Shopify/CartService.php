<?php

namespace App\Services\Shopify;

use App\Contracts\Services\CartServiceInterface;
use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\DTOs\Cart\CartDTO;
use App\Services\Base\BaseService;
use App\Exceptions\ShopifyApiException;
use App\Exceptions\ShopifyNotFoundException;
use Illuminate\Support\Facades\Log;

class CartService extends BaseService implements CartServiceInterface
{
    public function __construct(
        protected StorefrontApiClientInterface $storefrontClient
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

            $input = [];

            if ($accessToken) {
                $input['buyerIdentity'] = [
                    'customerAccessToken' => $accessToken,
                ];
            }

            // Ensure empty array is encoded as object {} not array []
            if (empty($input)) {
                $input = new \stdClass();
            }

            $variables = ['input' => $input, 'country' => $this->getCurrencyCountryCode()];

            $response = $this->storefrontClient->queryWithCurrency('storefront/cart/create_cart', $variables);

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

            $variables = ['cartId' => $cartId, 'country' => $this->getCurrencyCountryCode()];

            $response = $this->storefrontClient->queryWithCurrency('storefront/cart/get_cart', $variables);

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

            $variables = [
                'cartId' => $cartId,
                'lines' => [
                    [
                        'merchandiseId' => $variantId,
                        'quantity' => $quantity,
                    ],
                ],
                'country' => $this->getCurrencyCountryCode(),
            ];

            $response = $this->storefrontClient->queryWithCurrency('storefront/cart/add_line_item', $variables);

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
     * Add multiple line items to cart
     *
     * @param string $cartId Cart ID
     * @param array $lines Array of line items with merchandise_id and quantity
     * @return CartDTO
     */
    public function addLineItems(string $cartId, array $lines): CartDTO
    {
        try {
            $this->logPerformanceStart('addLineItems');

            // Transform lines to Shopify format
            $shopifyLines = array_map(function ($line) {
                $lineItem = [
                    'merchandiseId' => $line['merchandise_id'],
                    'quantity' => (int) $line['quantity'], // Ensure quantity is an integer
                ];

                // Only add attributes if they exist and are not empty
                if (!empty($line['attributes'])) {
                    $lineItem['attributes'] = $line['attributes'];
                }

                return $lineItem;
            }, $lines);

            $variables = [
                'cartId' => $cartId,
                'lines' => $shopifyLines,
                'country' => $this->getCurrencyCountryCode(),
            ];

            // Debug: Log what we're sending
            Log::info('CartService addLineItems - Variables being sent:', [
                'variables' => $variables,
                'shopifyLines' => $shopifyLines,
                'original_lines' => $lines,
            ]);

            $response = $this->storefrontClient->queryWithCurrency('storefront/cart/add_line_item', $variables);

            // Debug: Log the response
            Log::info('CartService addLineItems - Response received:', [
                'response' => $response,
            ]);

            if (!empty($response['data']['cartLinesAdd']['userErrors'])) {
                $errors = $response['data']['cartLinesAdd']['userErrors'];
                throw new ShopifyApiException('Failed to add line items: ' . json_encode($errors));
            }

            if (empty($response['data']['cartLinesAdd']['cart'])) {
                throw new ShopifyApiException('Add line items returned empty response');
            }

            $cart = CartDTO::fromShopifyResponse($response['data']['cartLinesAdd']['cart']);

            $this->logPerformanceEnd('addLineItems', [
                'cart_id' => $cartId,
                'lines_count' => count($lines),
            ]);

            return $cart;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to add line items', $e, [
                'cart_id' => $cartId,
                'lines_count' => count($lines),
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

            $variables = [
                'cartId' => $cartId,
                'lines' => [
                    [
                        'id' => $lineId,
                        'quantity' => (int) $quantity, // Ensure quantity is an integer
                    ],
                ],
                'country' => $this->getCurrencyCountryCode(),
            ];

            $response = $this->storefrontClient->queryWithCurrency('storefront/cart/update_line_item', $variables);

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

            $variables = [
                'cartId' => $cartId,
                'lineIds' => [$lineId],
                'country' => $this->getCurrencyCountryCode(),
            ];

            $response = $this->storefrontClient->queryWithCurrency('storefront/cart/remove_line_item', $variables);

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

            $variables = [
                'cartId' => $cartId,
                'buyerIdentity' => [
                    'customerAccessToken' => $accessToken,
                ],
                'country' => $this->getCurrencyCountryCode(),
            ];

            $response = $this->storefrontClient->queryWithCurrency('storefront/cart/associate_customer', $variables);

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

    /**
     * Update buyer identity with email
     *
     * @param string $cartId Cart identifier
     * @param string $email Customer email
     * @return CartDTO
     */
    public function updateBuyerIdentity(string $cartId, string $email): CartDTO
    {
        try {
            $this->logPerformanceStart('updateBuyerIdentity');

            $variables = [
                'cartId' => $cartId,
                'buyerIdentity' => [
                    'email' => $email,
                ],
                'country' => $this->getCurrencyCountryCode(),
            ];

            $response = $this->storefrontClient->queryWithCurrency('storefront/cart/associate_customer', $variables);

            if (!empty($response['data']['cartBuyerIdentityUpdate']['userErrors'])) {
                $errors = $response['data']['cartBuyerIdentityUpdate']['userErrors'];
                throw new ShopifyApiException('Failed to update buyer identity: ' . json_encode($errors));
            }

            if (empty($response['data']['cartBuyerIdentityUpdate']['cart'])) {
                throw new ShopifyApiException('Update buyer identity returned empty response');
            }

            $cart = CartDTO::fromShopifyResponse($response['data']['cartBuyerIdentityUpdate']['cart']);

            $this->logPerformanceEnd('updateBuyerIdentity', [
                'cart_id' => $cartId,
                'email' => $email,
            ]);

            return $cart;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to update buyer identity', $e, [
                'cart_id' => $cartId,
                'email' => $email,
            ]);
            throw $e;
        }
    }
}
