<?php

namespace App\Contracts\Services;

use App\DTOs\Cart\CartDTO;

interface CartServiceInterface
{
    /**
     * Create a new cart
     *
     * @param string|null $accessToken Optional customer access token
     * @return CartDTO
     */
    public function createCart(?string $accessToken = null): CartDTO;

    /**
     * Get cart by ID
     *
     * @param string $cartId Cart identifier
     * @return CartDTO
     */
    public function getCart(string $cartId): CartDTO;

    /**
     * Add a line item to cart
     *
     * @param string $cartId Cart identifier
     * @param string $variantId Product variant ID
     * @param int $quantity Quantity to add
     * @return CartDTO
     */
    public function addLineItem(string $cartId, string $variantId, int $quantity): CartDTO;

    /**
     * Update a line item quantity
     *
     * @param string $cartId Cart identifier
     * @param string $lineId Line item ID
     * @param int $quantity New quantity
     * @return CartDTO
     */
    public function updateLineItem(string $cartId, string $lineId, int $quantity): CartDTO;

    /**
     * Remove a line item from cart
     *
     * @param string $cartId Cart identifier
     * @param string $lineId Line item ID
     * @return CartDTO
     */
    public function removeLineItem(string $cartId, string $lineId): CartDTO;

    /**
     * Associate cart with customer
     *
     * @param string $cartId Cart identifier
     * @param string $accessToken Customer access token
     * @return CartDTO
     */
    public function associateCustomer(string $cartId, string $accessToken): CartDTO;

    /**
     * Update buyer identity with email
     *
     * @param string $cartId Cart identifier
     * @param string $email Customer email
     * @return CartDTO
     */
    public function updateBuyerIdentity(string $cartId, string $email): CartDTO;
}
