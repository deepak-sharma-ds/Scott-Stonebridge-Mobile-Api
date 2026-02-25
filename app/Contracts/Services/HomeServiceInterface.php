<?php

namespace App\Contracts\Services;

use App\DTOs\Home\HomeDTO;

/**
 * Home Service Interface
 * 
 * Defines the contract for home page data and newsletter operations.
 */
interface HomeServiceInterface
{
    /**
     * Get home page data
     * 
     * @param string $featuredTag Tag for featured products collection
     * @param int $featuredLimit Number of featured products to fetch
     * @param int $collectionsLimit Number of collections to fetch
     * @return HomeDTO
     */
    public function getHomePageData(
        string $featuredTag = 'featured',
        int $featuredLimit = 10,
        int $collectionsLimit = 6
    ): HomeDTO;

    /**
     * Subscribe customer to newsletter
     * 
     * @param string $email Customer email
     * @param string $accessToken Customer access token
     * @return bool
     */
    public function subscribeToNewsletter(string $email, string $accessToken): bool;
}
