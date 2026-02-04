<?php

declare(strict_types=1);

namespace App\Contracts\Shopify;

use App\DTOs\Shopify\CartDTO;

interface CartServiceInterface
{
    /**
     * Create a new guest cart
     */
    public function createGuestCart(
        array $lineItems = [],
        ?string $countryCode = null
    ): CartDTO;
    
    /**
     * Get cart by ID
     */
    public function getCart(string $cartId): ?CartDTO;
    
    /**
     * Add items to cart
     */
    public function addCartLines(string $cartId, array $lineItems): CartDTO;
    
    /**
     * Update cart line quantities
     */
    public function updateCartLines(string $cartId, array $updates): CartDTO;
    
    /**
     * Remove items from cart
     */
    public function removeCartLines(string $cartId, array $lineIds): CartDTO;
    
    /**
     * Update buyer identity (for currency/country context)
     */
    public function updateBuyerIdentity(
        string $cartId,
        ?string $email = null,
        ?string $phone = null,
        ?string $countryCode = null,
        ?string $customerAccessToken = null
    ): CartDTO;
    
    /**
     * Get checkout URL for cart
     */
    public function getCheckoutUrl(string $cartId): string;
    
    /**
     * Apply discount code to cart
     */
    public function applyDiscountCode(string $cartId, string $discountCode): CartDTO;
}
