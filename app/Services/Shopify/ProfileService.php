<?php

namespace App\Services\Shopify;

use App\Contracts\Services\ProfileServiceInterface;
use App\Contracts\Services\CustomerServiceInterface;
use App\Contracts\Shopify\AdminApiClientInterface;
use App\Contracts\Shopify\StorefrontApiClientInterface;
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
     * @param StorefrontApiClientInterface $storefrontClient Storefront API client for customer operations
     * @param CustomerServiceInterface $customerService Customer service for read operations
     */
    public function __construct(
        private readonly AdminApiClientInterface $adminClient,
        private readonly StorefrontApiClientInterface $storefrontClient,
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

            if (empty($response['data']['customerAddressCreate']['address'])) {
                throw new ShopifyApiException('Add address returned empty response');
            }

            $createdAddressId = $response['data']['customerAddressCreate']['address']['id'] ?? null;

            if (($data['is_default'] ?? false) && $createdAddressId !== null) {
                $this->setDefaultAddress($customerId, $createdAddressId);
            }

            // Fetch updated profile to get complete data
            $profile = $this->getProfile($accessToken);

            $this->logPerformanceEnd('addAddress', [
                'customer_id' => $customerId,
                'address_id' => $createdAddressId,
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
     * Updates an address using the Storefront API customerAddressUpdate mutation.
     * If is_default is true, sets the address as default using a separate mutation.
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
                'customerAccessToken' => $accessToken,
                'id' => $addressId,
                'address' => $this->formatAddressInput($data),
            ];

            $response = $this->storefrontClient->query('storefront/customer/update_customer_address', $variables);

            // Check for GraphQL errors first
            if (!empty($response['errors'])) {
                $errorMessage = 'Shopify GraphQL error: ' . json_encode($response['errors']);
                $this->logError($errorMessage, ['errors' => $response['errors'], 'address_id' => $addressId]);
                throw new ShopifyApiException($errorMessage);
            }

            // Check for user errors in the response
            if (!empty($response['data']['customerAddressUpdate']['customerUserErrors'])) {
                $errors = $response['data']['customerAddressUpdate']['customerUserErrors'];
                $errorMessage = 'Failed to update address: ' . json_encode($errors);
                $this->logError($errorMessage, ['errors' => $errors]);
                throw new ShopifyApiException($errorMessage);
            }

            if (empty($response['data']['customerAddressUpdate']['customerAddress'])) {
                throw new ShopifyApiException('Update address returned empty response');
            }

            // Set as default address if requested
            if (!empty($data['is_default'])) {
                $this->setDefaultAddressStorefront($accessToken, $addressId);
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
     * Deletes an address using the Storefront API customerAddressDelete mutation.
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
                'customerAccessToken' => $accessToken,
                'id' => $addressId,
            ];

            $response = $this->storefrontClient->query('storefront/customer/delete_customer_address', $variables);

            // Check for GraphQL errors first
            if (!empty($response['errors'])) {
                $errorMessage = 'Shopify GraphQL error: ' . json_encode($response['errors']);
                $this->logError($errorMessage, ['errors' => $response['errors'], 'address_id' => $addressId]);
                throw new ShopifyApiException($errorMessage);
            }

            // Check for user errors in the response
            if (!empty($response['data']['customerAddressDelete']['customerUserErrors'])) {
                $errors = $response['data']['customerAddressDelete']['customerUserErrors'];
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

    /**
     * Set the customer's default address using Storefront API.
     *
     * Uses the customerDefaultAddressUpdate mutation to set an address as default.
     *
     * @param string $accessToken Customer access token
     * @param string $addressId Address ID (Shopify GID)
     * @return void
     * @throws ShopifyApiException
     */
    private function setDefaultAddressStorefront(string $accessToken, string $addressId): void
    {
        $response = $this->storefrontClient->query('storefront/customer/set_default_address', [
            'customerAccessToken' => $accessToken,
            'addressId' => $addressId,
        ]);

        // Check for GraphQL errors
        if (!empty($response['errors'])) {
            $errorMessage = 'Failed to set default address (GraphQL error): ' . json_encode($response['errors']);
            $this->logError($errorMessage, ['errors' => $response['errors'], 'address_id' => $addressId]);
            throw new ShopifyApiException($errorMessage);
        }

        // Check for user errors
        if (!empty($response['data']['customerDefaultAddressUpdate']['customerUserErrors'])) {
            $errors = $response['data']['customerDefaultAddressUpdate']['customerUserErrors'];
            $errorMessage = 'Failed to set default address: ' . json_encode($errors);
            $this->logError($errorMessage, ['errors' => $errors, 'address_id' => $addressId]);
            throw new ShopifyApiException($errorMessage);
        }
    }

    /**
     * Set the customer's default address.
     *
     * Shopify handles default-address selection as a dedicated mutation for
     * newly created addresses and as an optional argument for updates.
     *
     * @param string $customerId
     * @param string $addressId
     * @return void
     * @throws ShopifyApiException
     */
    private function setDefaultAddress(string $customerId, string $addressId): void
    {
        $response = $this->adminClient->query('admin/customer/customer_update_default_address', [
            'customerId' => $customerId,
            'addressId' => $addressId,
        ]);

        if (!empty($response['data']['customerUpdateDefaultAddress']['userErrors'])) {
            $errors = $response['data']['customerUpdateDefaultAddress']['userErrors'];
            $errorMessage = 'Failed to set default address: ' . json_encode($errors);
            $this->logError($errorMessage, ['errors' => $errors, 'address_id' => $addressId]);
            throw new ShopifyApiException($errorMessage);
        }
    }
}
