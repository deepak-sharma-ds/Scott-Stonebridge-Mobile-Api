<?php

namespace App\Services\Shopify;

use App\Contracts\Services\CustomerServiceInterface;
use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\DTOs\Customer\CustomerDTO;
use App\DTOs\Customer\AddressDTO;
use App\Services\Base\BaseService;
use App\Exceptions\ShopifyApiException;
use App\Exceptions\ShopifyNotFoundException;

class CustomerService extends BaseService implements CustomerServiceInterface
{
    public function __construct(
        protected StorefrontApiClientInterface $storefrontClient
    ) {
        parent::__construct();
    }

    /**
     * Get customer details
     *
     * @param string $accessToken Customer access token
     * @return CustomerDTO
     */
    public function getCustomer(string $accessToken): CustomerDTO
    {
        try {
            $this->logPerformanceStart('getCustomer');

            $variables = ['customerAccessToken' => $accessToken];

            $response = $this->storefrontClient->query('storefront/customer/get_customer_profile', $variables);

            if (empty($response['data']['customer'])) {
                throw new ShopifyNotFoundException('Customer not found or invalid access token');
            }

            $customer = CustomerDTO::fromShopifyResponse($response['data']['customer']);

            $this->logPerformanceEnd('getCustomer', [
                'customer_id' => $customer->id,
            ]);

            return $customer;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch customer', $e);
            throw $e;
        }
    }

    /**
     * Update customer information
     *
     * @param string $accessToken Customer access token
     * @param array $data Customer data to update
     * @return CustomerDTO
     */
    public function updateCustomer(string $accessToken, array $data): CustomerDTO
    {
        try {
            $this->logPerformanceStart('updateCustomer');

            $customerInput = [];
            
            if (isset($data['firstName'])) {
                $customerInput['firstName'] = $data['firstName'];
            }
            if (isset($data['lastName'])) {
                $customerInput['lastName'] = $data['lastName'];
            }
            if (isset($data['email'])) {
                $customerInput['email'] = $data['email'];
            }
            if (isset($data['phone'])) {
                $customerInput['phone'] = $data['phone'];
            }
            if (isset($data['acceptsMarketing'])) {
                $customerInput['acceptsMarketing'] = $data['acceptsMarketing'];
            }

            $variables = [
                'customerAccessToken' => $accessToken,
                'customer' => $customerInput,
            ];

            $response = $this->storefrontClient->query('storefront/customer/update_customer_profile', $variables);

            if (!empty($response['data']['customerUpdate']['customerUserErrors'])) {
                $errors = $response['data']['customerUpdate']['customerUserErrors'];
                throw new ShopifyApiException('Failed to update customer: ' . json_encode($errors));
            }

            if (empty($response['data']['customerUpdate']['customer'])) {
                throw new ShopifyApiException('Customer update returned empty response');
            }

            // Fetch full customer profile to get complete data including addresses
            $customer = $this->getCustomer($accessToken);

            $this->logPerformanceEnd('updateCustomer', [
                'customer_id' => $customer->id,
            ]);

            return $customer;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to update customer', $e, ['data' => $data]);
            throw $e;
        }
    }

    /**
     * Add a new address
     *
     * @param string $accessToken Customer access token
     * @param array $addressData Address data
     * @return AddressDTO
     */
    public function addAddress(string $accessToken, array $addressData): AddressDTO
    {
        try {
            $this->logPerformanceStart('addAddress');

            $variables = [
                'customerAccessToken' => $accessToken,
                'address' => $addressData,
            ];

            $response = $this->storefrontClient->query('storefront/customer/add_customer_address', $variables);

            if (!empty($response['data']['customerAddressCreate']['customerUserErrors'])) {
                $errors = $response['data']['customerAddressCreate']['customerUserErrors'];
                throw new ShopifyApiException('Failed to add address: ' . json_encode($errors));
            }

            if (empty($response['data']['customerAddressCreate']['customerAddress'])) {
                throw new ShopifyApiException('Add address returned empty response');
            }

            $address = AddressDTO::fromShopifyResponse($response['data']['customerAddressCreate']['customerAddress']);

            $this->logPerformanceEnd('addAddress', [
                'address_id' => $address->id,
            ]);

            return $address;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to add address', $e, ['address_data' => $addressData]);
            throw $e;
        }
    }

    /**
     * Update an existing address
     *
     * @param string $accessToken Customer access token
     * @param string $addressId Address identifier
     * @param array $addressData Address data to update
     * @return AddressDTO
     */
    public function updateAddress(string $accessToken, string $addressId, array $addressData): AddressDTO
    {
        try {
            $this->logPerformanceStart('updateAddress');

            $variables = [
                'customerAccessToken' => $accessToken,
                'id' => $addressId,
                'address' => $addressData,
            ];

            $response = $this->storefrontClient->query('storefront/customer/update_customer_address', $variables);

            if (!empty($response['data']['customerAddressUpdate']['customerUserErrors'])) {
                $errors = $response['data']['customerAddressUpdate']['customerUserErrors'];
                throw new ShopifyApiException('Failed to update address: ' . json_encode($errors));
            }

            if (empty($response['data']['customerAddressUpdate']['customerAddress'])) {
                throw new ShopifyApiException('Update address returned empty response');
            }

            $address = AddressDTO::fromShopifyResponse($response['data']['customerAddressUpdate']['customerAddress']);

            $this->logPerformanceEnd('updateAddress', [
                'address_id' => $addressId,
            ]);

            return $address;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to update address', $e, [
                'address_id' => $addressId,
                'address_data' => $addressData,
            ]);
            throw $e;
        }
    }

    /**
     * Delete an address
     *
     * @param string $accessToken Customer access token
     * @param string $addressId Address identifier
     * @return bool
     */
    public function deleteAddress(string $accessToken, string $addressId): bool
    {
        try {
            $this->logPerformanceStart('deleteAddress');

            $variables = [
                'customerAccessToken' => $accessToken,
                'id' => $addressId,
            ];

            $response = $this->storefrontClient->query('storefront/customer/delete_customer_address', $variables);

            if (!empty($response['data']['customerAddressDelete']['customerUserErrors'])) {
                $errors = $response['data']['customerAddressDelete']['customerUserErrors'];
                throw new ShopifyApiException('Failed to delete address: ' . json_encode($errors));
            }

            $deleted = !empty($response['data']['customerAddressDelete']['deletedCustomerAddressId']);

            $this->logPerformanceEnd('deleteAddress', [
                'address_id' => $addressId,
                'deleted' => $deleted,
            ]);

            return $deleted;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to delete address', $e, ['address_id' => $addressId]);
            throw $e;
        }
    }
}
