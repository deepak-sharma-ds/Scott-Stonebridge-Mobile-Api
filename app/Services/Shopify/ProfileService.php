<?php

namespace App\Services\Shopify;

use App\Contracts\Services\ProfileServiceInterface;
use App\Contracts\Services\CustomerServiceInterface;
use App\Contracts\Shopify\AdminApiClientInterface;
use App\DTOs\Profile\ProfileDTO;
use App\DTOs\Customer\CustomerDTO;
use App\Exceptions\ShopifyApiException;
use App\Services\Base\BaseService;

/**
 * Profile Service
 * 
 * Handles customer profile management operations using the Shopify Admin API.
 * Provides methods for retrieving and updating customer profiles and addresses.
 * 
 * This service uses the Admin API for write operations (update, create, delete)
 * and reuses CustomerService for read operations to maintain consistency.
 * 
 * Requirements: 5.1
 */
class ProfileService extends BaseService implements ProfileServiceInterface
{
    /**
     * Constructor
     * 
     * @param AdminApiClientInterface $adminClient Admin API client for mutations
     * @param CustomerServiceInterface $customerService Customer service for read operations
     */
    public function __construct(
        private readonly AdminApiClientInterface $adminClient,
        private readonly CustomerServiceInterface $customerService
    ) {
        parent::__construct();
    }

    /**
     * Get customer profile
     * 
     * Retrieves the customer profile by reusing CustomerService and converting
     * the result to a ProfileDTO. This ensures consistency with existing
     * customer data retrieval logic.
     * 
     * @param string $accessToken Customer access token
     * @return ProfileDTO
     * @throws ShopifyApiException
     */
    public function getProfile(string $accessToken): ProfileDTO
    {
        try {
            $this->logPerformanceStart('getProfile');

            $customer = $this->customerService->getCustomer($accessToken);
            $profile = ProfileDTO::fromCustomerDTO($customer);

            $this->logPerformanceEnd('getProfile', [
                'customer_id' => $profile->id,
            ]);

            return $profile;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch profile', $e);
            throw $e;
        }
    }

    /**
     * Update customer profile
     * 
     * Updates customer information using the Admin API customer_update mutation.
     * Handles first name, last name, phone, and marketing preferences.
     * 
     * @param string $accessToken Customer access token
     * @param array $data Profile data to update (first_name, last_name, phone, accepts_marketing)
     * @return ProfileDTO
     * @throws ShopifyApiException
     */
    public function updateProfile(string $accessToken, array $data): ProfileDTO
    {
        try {
            $this->logPerformanceStart('updateProfile');

            $customerId = $this->getCustomerIdFromToken($accessToken);

            $variables = [
                'id' => $customerId,
                'firstName' => $data['first_name'] ?? null,
                'lastName' => $data['last_name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'acceptsMarketing' => $data['accepts_marketing'] ?? false,
            ];

            $response = $this->adminClient->query('admin/customer/customer_update', $variables);

            // Check for user errors in the response
            if (!empty($response['data']['customerUpdate']['userErrors'])) {
                $errors = $response['data']['customerUpdate']['userErrors'];
                $errorMessage = 'Failed to update profile: ' . json_encode($errors);
                $this->logError($errorMessage, ['errors' => $errors]);
                throw new ShopifyApiException($errorMessage);
            }

            if (empty($response['data']['customerUpdate']['customer'])) {
                throw new ShopifyApiException('Profile update returned empty response');
            }

            $profile = ProfileDTO::fromShopifyResponse($response['data']['customerUpdate']['customer']);

            $this->logPerformanceEnd('updateProfile', [
                'customer_id' => $customerId,
            ]);

            return $profile;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to update profile', $e, ['data' => $data]);
            throw $e;
        }
    }

    /**
     * Add a new address to customer profile
     * 
     * Creates a new address using the Admin API customer_address_create mutation.
     * Returns the updated profile with the new address included.
     * 
     * @param string $accessToken Customer access token
     * @param array $data Address data (address1, address2, city, province, country, zip, phone, first_name, last_name)
     * @return ProfileDTO
     * @throws ShopifyApiException
     */
    public function addAddress(string $accessToken, array $data): ProfileDTO
    {
        try {
            $this->logPerformanceStart('addAddress');

            $customerId = $this->getCustomerIdFromToken($accessToken);

            $variables = [
                'customerId' => $customerId,
                'address' => $this->formatAddressInput($data),
            ];

            $response = $this->adminClient->query('admin/customer/customer_address_create', $variables);

            // Check for user errors in the response
            if (!empty($response['data']['customerAddressCreate']['userErrors'])) {
                $errors = $response['data']['customerAddressCreate']['userErrors'];
                $errorMessage = 'Failed to add address: ' . json_encode($errors);
                $this->logError($errorMessage, ['errors' => $errors]);
                throw new ShopifyApiException($errorMessage);
            }

            if (empty($response['data']['customerAddressCreate']['customerAddress'])) {
                throw new ShopifyApiException('Add address returned empty response');
            }

            // Fetch updated profile to get complete data
            $profile = $this->getProfile($accessToken);

            $this->logPerformanceEnd('addAddress', [
                'customer_id' => $customerId,
                'address_id' => $response['data']['customerAddressCreate']['customerAddress']['id'] ?? null,
            ]);

            return $profile;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to add address', $e, ['address_data' => $data]);
            throw $e;
        }
    }

    /**
     * Update an existing address
     * 
     * Updates an address using the Admin API customer_address_update mutation.
     * Returns the updated profile with the modified address.
     * 
     * @param string $accessToken Customer access token
     * @param string $addressId Address identifier (Shopify GID)
     * @param array $data Address data to update
     * @return ProfileDTO
     * @throws ShopifyApiException
     */
    public function updateAddress(string $accessToken, string $addressId, array $data): ProfileDTO
    {
        try {
            $this->logPerformanceStart('updateAddress');

            $variables = [
                'id' => $addressId,
                'address' => $this->formatAddressInput($data),
            ];

            $response = $this->adminClient->query('admin/customer/customer_address_update', $variables);

            // Check for user errors in the response
            if (!empty($response['data']['customerAddressUpdate']['userErrors'])) {
                $errors = $response['data']['customerAddressUpdate']['userErrors'];
                $errorMessage = 'Failed to update address: ' . json_encode($errors);
                $this->logError($errorMessage, ['errors' => $errors]);
                throw new ShopifyApiException($errorMessage);
            }

            if (empty($response['data']['customerAddressUpdate']['customerAddress'])) {
                throw new ShopifyApiException('Update address returned empty response');
            }

            // Fetch updated profile to get complete data
            $profile = $this->getProfile($accessToken);

            $this->logPerformanceEnd('updateAddress', [
                'address_id' => $addressId,
            ]);

            return $profile;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to update address', $e, [
                'address_id' => $addressId,
                'address_data' => $data,
            ]);
            throw $e;
        }
    }

    /**
     * Delete an address from customer profile
     * 
     * Deletes an address using the Admin API customer_address_delete mutation.
     * This operation does not return a value on success.
     * 
     * @param string $accessToken Customer access token
     * @param string $addressId Address identifier (Shopify GID)
     * @return void
     * @throws ShopifyApiException
     */
    public function deleteAddress(string $accessToken, string $addressId): void
    {
        try {
            $this->logPerformanceStart('deleteAddress');

            $variables = [
                'id' => $addressId,
            ];

            $response = $this->adminClient->query('admin/customer/customer_address_delete', $variables);

            // Check for user errors in the response
            if (!empty($response['data']['customerAddressDelete']['userErrors'])) {
                $errors = $response['data']['customerAddressDelete']['userErrors'];
                $errorMessage = 'Failed to delete address: ' . json_encode($errors);
                $this->logError($errorMessage, ['errors' => $errors]);
                throw new ShopifyApiException($errorMessage);
            }

            $deleted = !empty($response['data']['customerAddressDelete']['deletedCustomerAddressId']);

            if (!$deleted) {
                throw new ShopifyApiException('Delete address operation failed');
            }

            $this->logPerformanceEnd('deleteAddress', [
                'address_id' => $addressId,
                'deleted' => $deleted,
            ]);
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to delete address', $e, ['address_id' => $addressId]);
            throw $e;
        }
    }

    /**
     * Get customer ID from access token
     * 
     * Retrieves the customer ID by fetching the customer data using the access token.
     * This is needed for Admin API mutations which require the customer GID.
     * 
     * @param string $accessToken Customer access token
     * @return string Customer ID (Shopify GID)
     * @throws ShopifyApiException
     */
    private function getCustomerIdFromToken(string $accessToken): string
    {
        try {
            $customer = $this->customerService->getCustomer($accessToken);
            return $customer->id;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to get customer ID from token', $e);
            throw new ShopifyApiException('Invalid access token or customer not found', 0, $e);
        }
    }

    /**
     * Format address input for Shopify API
     * 
     * Transforms address data from the API request format to the format
     * expected by Shopify's MailingAddressInput GraphQL input type.
     * 
     * @param array $data Raw address data from request
     * @return array Formatted address data for Shopify API
     */
    private function formatAddressInput(array $data): array
    {
        return [
            'address1' => $data['address1'],
            'address2' => $data['address2'] ?? null,
            'city' => $data['city'],
            'province' => $data['province'] ?? null,
            'country' => $data['country'],
            'zip' => $data['zip'],
            'phone' => $data['phone'] ?? null,
            'firstName' => $data['first_name'] ?? null,
            'lastName' => $data['last_name'] ?? null,
        ];
    }
}
