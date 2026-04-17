<?php

namespace App\Contracts\Services;

use App\DTOs\Wishlist\WishlistDTO;

/**
 * Wishlist Service Interface
 * 
 * Defines the contract for wishlist management operations.
 */
interface WishlistServiceInterface
{
    /**
     * Get customer wishlist
     * 
     * @param string $accessToken Customer access token
     * @return WishlistDTO
     */
    public function getWishlist(string $accessToken): WishlistDTO;

    /**
     * Add item to wishlist
     * 
     * @param string $accessToken Customer access token
     * @param string $productId Shopify product ID
     * @return WishlistDTO
     */
    public function addItem(string $accessToken, string $productId): WishlistDTO;

    /**
     * Remove item from wishlist
     * 
     * @param string $accessToken Customer access token
     * @param string $productId Shopify product ID
     * @return WishlistDTO
     */
    public function removeItem(string $accessToken, string $productId): WishlistDTO;
}
