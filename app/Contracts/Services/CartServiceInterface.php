<?php

namespace App\Contracts\Services;

use App\DTOs\Cart\CartDTO;

interface CartServiceInterface
{
    /**
     * Create a new cart
     *
     * @param  string|null  $accessToken  Optional customer access token
     */
    public function createCart(?string $accessToken = null): CartDTO;

    /**
     * Get cart by ID
     *
     * @param  string  $cartId  Cart identifier
     */
    public function getCart(string $cartId): CartDTO;

    /**
     * Add a line item to cart
     *
     * @param  string  $cartId  Cart identifier
     * @param  string  $variantId  Product variant ID
     * @param  int  $quantity  Quantity to add
     */
    public function addLineItem(string $cartId, string $variantId, int $quantity): CartDTO;

    /**
     * Add a line item to cart
     *
     * @param  string  $cartId  Cart identifier
     * @param  array  $lines  Line items to add
     */
    public function addLineItems(string $cartId, array $lines): CartDTO;

    /**
     * Update a line item quantity
     *
     * @param  string  $cartId  Cart identifier
     * @param  string  $lineId  Line item ID
     * @param  int  $quantity  New quantity
     */
    public function updateLineItem(string $cartId, string $lineId, int $quantity): CartDTO;

    /**
     * Remove a line item from cart
     *
     * @param  string  $cartId  Cart identifier
     * @param  string  $lineId  Line item ID
     */
    public function removeLineItem(string $cartId, string $lineId): CartDTO;

    /**
     * Associate cart with customer
     *
     * @param  string  $cartId  Cart identifier
     * @param  string  $accessToken  Customer access token
     */
    public function associateCustomer(string $cartId, string $accessToken): CartDTO;

    /**
     * Update buyer identity with email
     *
     * @param  string  $cartId  Cart identifier
     * @param  string  $email  Customer email
     */
    public function updateBuyerIdentity(string $cartId, string $email): CartDTO;
}
