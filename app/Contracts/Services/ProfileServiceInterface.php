<?php

namespace App\Contracts\Services;

use App\DTOs\Profile\ProfileDTO;

/**
 * Profile Service Interface
 * 
 * Defines the contract for customer profile and address management operations.
 */
interface ProfileServiceInterface
{
    /**
     * Get customer profile
     * 
     * @param string $accessToken Customer access token
     * @return ProfileDTO
     */
    public function getProfile(string $accessToken): ProfileDTO;

    /**
     * Update customer profile
     * 
     * @param string $accessToken Customer access token
     * @param array $data Profile data to update
     * @return ProfileDTO
     */
    public function updateProfile(string $accessToken, array $data): ProfileDTO;

    /**
     * Add a new address to customer profile
     * 
     * @param string $accessToken Customer access token
     * @param array $data Address data
     * @return ProfileDTO
     */
    public function addAddress(string $accessToken, array $data): ProfileDTO;

    /**
     * Update an existing address
     * 
     * @param string $accessToken Customer access token
     * @param string $addressId Address identifier
     * @param array $data Address data to update
     * @return ProfileDTO
     */
    public function updateAddress(string $accessToken, string $addressId, array $data): ProfileDTO;

    /**
     * Delete an address from customer profile
     * 
     * @param string $accessToken Customer access token
     * @param string $addressId Address identifier
     * @return void
     */
    public function deleteAddress(string $accessToken, string $addressId): void;
}
