<?php

namespace App\Http\Controllers\Apis;

use App\Http\Controllers\Controller;
use App\Services\APIShopifyService;
use App\Facades\Shopify;
use App\Http\Requests\Customer\AddCustomerAddressRequest;
use App\Http\Requests\Customer\DeleteCustomerAddressRequest;
use App\Http\Requests\Customer\UpdateCustomerAddressRequest;
use App\Http\Requests\Customer\UpdateCustomerProfileRequest;
use App\Traits\ShopifyResponseFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProfileController extends Controller
{
    use ShopifyResponseFormatter;

    protected $shopify;
    protected $customerAccessToken;

    public function __construct(APIShopifyService $shopify, Request $request)
    {
        $this->shopify = $shopify;
        $this->customerAccessToken = $request->bearerToken();
    }

    /**
     * Get customer profile + addresses
     */
    public function index(Request $request)
    {
        try {
            $vars = [
                'customerAccessToken' => $this->customerAccessToken,
            ];

            // -----------------------------------------------------
            // Shopify query (Storefront API) using new architecture
            // -----------------------------------------------------
            $response = Shopify::query(
                'storefront',
                'customer/get_customer_profile',
                $vars
            );

            $customer = data_get($response, 'data.customer');

            if (!$customer) {
                return $this->fail('Customer not found');
            }

            // -----------------------------------------------------
            // Refine Nested Edges (addresses)
            // -----------------------------------------------------
            $customer = $this->refineNestedEdges($customer);

            return $this->success('Customer details fetched successfully', $customer);
        } catch (\Throwable $e) {
            return $this->fail('Something went wrong.', $e->getMessage());
        }
    }

    /**
     * Update customer profile (Storefront)
     */
    public function updateProfile(UpdateCustomerProfileRequest $request)
    {
        try {
            $validated = $request->validated();

            $vars = [
                'customerAccessToken' => $this->customerAccessToken,
                'customer' => [
                    'firstName' => $validated['firstName'],
                    'lastName'  => $validated['lastName'],
                    'email'     => $validated['email'],
                    'phone'     => $validated['phone'],
                ],
            ];

            // -----------------------------------------------------
            // GraphQL Call using new architecture
            // -----------------------------------------------------
            $response = Shopify::query(
                'storefront',
                'customer/update_customer_profile',
                $vars
            );

            $result = data_get($response, 'data.customerUpdate');

            if (!$result) {
                return $this->fail('Failed to update customer details');
            }

            // Check for Shopify validation errors
            $errors = data_get($result, 'customerUserErrors', []);
            if (!empty($errors)) {
                return $this->fail('Failed to update customer details', $errors);
            }

            $customer = data_get($result, 'customer');

            return $this->success('Customer details updated successfully', $customer);
        } catch (\Throwable $e) {
            return $this->fail('Something went wrong.', $e->getMessage());
        }
    }


    /**
     * Add customer address
     */
    public function addAddress(AddCustomerAddressRequest $request)
    {
        try {
            $vars = [
                'customerAccessToken' => $this->customerAccessToken,
                'address' => [
                    'address1'  => $request->address1,
                    'address2'  => $request->address2,
                    'city'      => $request->city,
                    'company'   => $request->company,
                    'country'   => $request->country,
                    'firstName' => $request->firstName,
                    'lastName'  => $request->lastName,
                    'phone'     => $request->phone,
                    'province'  => $request->province,
                    'zip'       => $request->zip,
                ],
            ];

            // -----------------------------------------------------
            // Shopify GraphQL Request
            // -----------------------------------------------------
            $response = Shopify::query(
                'storefront',
                'customer/add_customer_address',
                $vars
            );

            $result = data_get($response, 'data.customerAddressCreate');

            if (!$result) {
                return $this->fail('Failed to create address!');
            }

            // Shopify user-level input errors
            if (!empty($result['customerUserErrors'])) {
                return $this->fail('Failed to create customer address', $result['customerUserErrors']);
            }

            return $this->success('Address created successfully', [
                'address' => $result['customerAddress'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return $this->fail('Something went wrong.', $e->getMessage());
        }
    }


    /**
     * Update customer address
     */
    public function updateAddress(UpdateCustomerAddressRequest $request)
    {
        try {
            $vars = [
                'customerAccessToken' => $this->customerAccessToken,
                'id' => $request->address_id,
                'address' => [
                    'address1'  => $request->address1,
                    'address2'  => $request->address2,
                    'city'      => $request->city,
                    'company'   => $request->company,
                    'country'   => $request->country,
                    'firstName' => $request->firstName,
                    'lastName'  => $request->lastName,
                    'phone'     => $request->phone,
                    'province'  => $request->province,
                    'zip'       => $request->zip,
                ],
            ];

            $response = Shopify::query(
                'storefront',
                'customer/update_customer_address',
                $vars
            );

            $result = data_get($response, 'data.customerAddressUpdate');

            if (!$result) {
                return $this->fail('Failed to update address');
            }

            // Shopify-level user errors
            $userErrors = data_get($result, 'customerUserErrors', []);
            if (!empty($userErrors)) {
                return $this->fail('Failed to update address', $userErrors);
            }

            $address = data_get($result, 'customerAddress');

            // return normalized address
            return $this->success('Address updated successfully', [
                'address' => $address,
            ]);
        } catch (\Throwable $e) {
            return $this->fail('Something went wrong.', $e->getMessage());
        }
    }

    /**
     * Delete customer address
     */
    public function deleteAddress(DeleteCustomerAddressRequest $request)
    {
        try {
            $vars = [
                'customerAccessToken' => $this->customerAccessToken,
                'id' => $request->address_id,
            ];

            // -----------------------------------------------------
            // Shopify GraphQL Request
            // -----------------------------------------------------
            $response = Shopify::query(
                'storefront',
                'customer/delete_customer_address',
                $vars
            );

            $result = data_get($response, 'data.customerAddressDelete');

            if (!$result) {
                return $this->fail('Failed to delete customer address');
            }

            // Shopify validation errors
            $userErrors = data_get($result, 'customerUserErrors', []);
            if (!empty($userErrors)) {
                return $this->fail('Failed to delete customer address', $userErrors);
            }

            return $this->success('Address deleted successfully', [
                'deletedAddressId' => $result['deletedCustomerAddressId'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return $this->fail('Something went wrong.', $e->getMessage());
        }
    }
}
