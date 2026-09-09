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
     * @param  string  $accessToken  Customer access token
     */
    public function getProfile(string $accessToken): ProfileDTO;

    /**
     * Update customer profile
     *
     * @param  string  $accessToken  Customer access token
     * @param  array  $data  Profile data to update
     */
    public function updateProfile(string $accessToken, array $data): ProfileDTO;

    /**
     * Add a new address to customer profile
     *
     * @param  string  $accessToken  Customer access token
     * @param  array  $data  Address data
     */
    public function addAddress(string $accessToken, array $data): ProfileDTO;

    /**
     * Update an existing address
     *
     * @param  string  $accessToken  Customer access token
     * @param  string  $addressId  Address identifier
     * @param  array  $data  Address data to update
     */
    public function updateAddress(string $accessToken, string $addressId, array $data): ProfileDTO;

    /**
     * Delete an address from customer profile
     *
     * @param  string  $accessToken  Customer access token
     * @param  string  $addressId  Address identifier
     */
    public function deleteAddress(string $accessToken, string $addressId): void;
}
