<?php

namespace App\Contracts\Services;

use App\DTOs\Customer\CustomerDTO;
use App\DTOs\Customer\AddressDTO;

interface CustomerServiceInterface
{
    /**
     * Get customer details
     *
     * @param string $accessToken Customer access token
     * @return CustomerDTO
     */
    public function getCustomer(string $accessToken): CustomerDTO;

    /**
     * Update customer information
     *
     * @param string $accessToken Customer access token
     * @param array $data Customer data to update
     * @return CustomerDTO
     */
    public function updateCustomer(string $accessToken, array $data): CustomerDTO;

    /**
     * Add a new address
     *
     * @param string $accessToken Customer access token
     * @param array $addressData Address data
     * @return AddressDTO
     */
    public function addAddress(string $accessToken, array $addressData): AddressDTO;

    /**
     * Update an existing address
     *
     * @param string $accessToken Customer access token
     * @param string $addressId Address identifier
     * @param array $addressData Address data to update
     * @return AddressDTO
     */
    public function updateAddress(string $accessToken, string $addressId, array $addressData): AddressDTO;

    /**
     * Delete an address
     *
     * @param string $accessToken Customer access token
     * @param string $addressId Address identifier
     * @return bool
     */
    public function deleteAddress(string $accessToken, string $addressId): bool;
}
