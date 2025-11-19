<?php

namespace App\Http\Controllers\Apis;

use App\Http\Controllers\Controller;
use App\Services\APIShopifyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProfileController extends Controller
{
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

            $customerAccessToken = $this->customerAccessToken;

            $query = <<<'GRAPHQL'
                query getCustomer($customerAccessToken: String!) {
                    customer(customerAccessToken: $customerAccessToken) {
                        id
                        firstName
                        lastName
                        email
                        phone
                        addresses(first: 100) {
                            edges {
                                node {
                                    id
                                    address1
                                    address2
                                    city
                                    company
                                    country
                                    firstName
                                    lastName
                                    phone
                                    province
                                    zip
                                }
                            }
                        }
                    }
                }
                GRAPHQL;

            $variables = [
                "customerAccessToken" => $customerAccessToken
            ];

            $data = $this->shopify->storefrontApiRequest($query, $variables);
            if (isset($data['errors'])) {
                return response()->json([
                    'status' => 500,
                    'message' => 'Failed to fetch customer details',
                    'errors' => $data['errors'],
                ], 500);
            }

            $getCustomer = data_get($data, 'data.getCustomer');
            if (!$getCustomer) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Cart not found',
                ], 404);
            }

            return response()->json([
                'status' => 200,
                'message' => 'Customer details found successfully',
                'data' => $getCustomer,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => 'Something went wrong.',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Update customer profile (Storefront)
     */
    public function updateProfile(Request $request)
    {
        try {

            $customerAccessToken = $this->customerAccessToken;

            // Validate request
            $validator = Validator::make($request->all(), [
                'firstName' => ['required', 'string', 'max:100'],
                'lastName' => ['required', 'string', 'max:100'],
                'email' => ['required', 'string', 'email', 'max:255'],
                'phone' => ['required', 'string', 'max:100'],
            ]);

            if ($validator->fails()) {
                response()->json([
                    'success' => 422,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }
            $validated = $validator->validated();

            $query = <<<'GRAPHQL'
                mutation updateCustomer(
                    $customerAccessToken: String!,
                    $customer: CustomerUpdateInput!
                ) {
                    customerUpdate(customerAccessToken: $customerAccessToken, customer: $customer) {
                        customer {
                            id
                            firstName
                            lastName
                            email
                            phone
                        }
                        customerUserErrors {
                            field
                            message
                        }
                    }
                }
                GRAPHQL;

            $variables = [
                "customerAccessToken" => $customerAccessToken,
                "customer" => [
                    "firstName" => $validated['first_name'],
                    "lastName"  => $validated['last_name'],
                    "email"     => $validated['email'],
                    "phone"     => $validated['phone'],
                ]
            ];

            $data = $this->shopify->storefrontApiRequest($query, $variables);
            if (isset($data['errors'])) {
                return response()->json([
                    'status' => 500,
                    'message' => 'Failed to update customer details',
                    'errors' => $data['errors'],
                ], 500);
            }

            $updateCustomer = data_get($data, 'data.updateCustomer');
            if (!$updateCustomer) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Customer not found!',
                ], 404);
            }

            return response()->json([
                'status' => 200,
                'message' => 'Customer details update successfully',
                'data' => $updateCustomer,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => 'Something went wrong.',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Add customer address
     */
    public function addAddress(Request $request)
    {
        try {

            $customerAccessToken = $this->customerAccessToken;

            // Validate request
            $validator = Validator::make($request->all(), [
                'address1'   => ['required', 'string', 'max:150'],
                'address2'   => ['nullable', 'string', 'max:150'],
                'city'       => ['required', 'string', 'max:100'],
                'company'    => ['nullable', 'string', 'max:100'],
                'country'    => ['required', 'string', 'max:100'],
                'firstName'  => ['required', 'string', 'max:100'],
                'lastName'   => ['required', 'string', 'max:100'],
                'phone'      => ['nullable', 'string', 'max:20'],
                'province'   => ['nullable', 'string', 'max:100'],
                'zip'        => ['required', 'string', 'max:20'],
            ]);


            if ($validator->fails()) {
                response()->json([
                    'success' => 422,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }
            $validated = $validator->validated();

            $query = <<<'GRAPHQL'
            mutation customerAddressCreate(
                $customerAccessToken: String!,
                $address: MailingAddressInput!
            ) {
                customerAddressCreate(customerAccessToken: $customerAccessToken, address: $address) {
                    customerAddress {
                        id
                        address1
                        address2
                        city
                        company
                        country
                        firstName
                        lastName
                        phone
                        province
                        zip
                    }
                    customerUserErrors {
                        message
                    }
                }
            }
            GRAPHQL;

            $variables = [
                "customerAccessToken" => $customerAccessToken,
                "address" => [
                    "address1"   => $validated['address1'],
                    "address2"   => $validated['address2'],
                    "city"       => $validated['city'],
                    "company"    => $validated['company'],
                    "country"    => $validated['country'],
                    "firstName"  => $validated['firstName'],
                    "lastName"   => $validated['lastName'],
                    "phone"      => $validated['phone'],
                    "province"   => $validated['province'],
                    "zip"        => $validated['zip'],
                ]
            ];

            $data = $this->shopify->storefrontApiRequest($query, $variables);
            if (isset($data['errors'])) {
                return response()->json([
                    'status' => 500,
                    'message' => 'Failed to create customer address',
                    'errors' => $data['errors'],
                ], 500);
            }

            $customerAddressCreate = data_get($data, 'data.customerAddressCreate');
            if (!$customerAddressCreate) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Failed to create address!',
                ], 404);
            }

            return response()->json([
                'status' => 200,
                'message' => 'Address created successfully',
                'data' => $customerAddressCreate,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => 'Something went wrong.',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Update customer address
     */
    public function updateAddress(Request $request)
    {
        try {

            $customerAccessToken = $this->customerAccessToken;

            // Validate request
            $validator = Validator::make($request->all(), [
                'address_id'    => ['required'],
                'address1'      => ['required', 'string', 'max:150'],
                'address2'      => ['nullable', 'string', 'max:150'],
                'city'          => ['required', 'string', 'max:100'],
                'company'       => ['nullable', 'string', 'max:100'],
                'country'       => ['required', 'string', 'max:100'],
                'firstName'     => ['required', 'string', 'max:100'],
                'lastName'      => ['required', 'string', 'max:100'],
                'phone'         => ['nullable', 'string', 'max:20'],
                'province'      => ['nullable', 'string', 'max:100'],
                'zip'           => ['required', 'string', 'max:20'],
            ]);


            if ($validator->fails()) {
                response()->json([
                    'success' => 422,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }
            $validated = $validator->validated();

            $query = <<<'GRAPHQL'
                mutation customerAddressUpdate(
                    $customerAccessToken: String!,
                    $id: ID!,
                    $address: MailingAddressInput!
                ) {
                    customerAddressUpdate(customerAccessToken: $customerAccessToken, id: $id, address: $address) {
                        customerAddress {
                            id
                            address1
                            address2
                            city
                            company
                            country
                            firstName
                            lastName
                            phone
                            province
                            zip
                        }
                        customerUserErrors {
                            message
                        }
                    }
                }
                GRAPHQL;

            $variables = [
                "customerAccessToken" => $customerAccessToken,
                "id" => $validated['address_id'],
                "address" => [
                    "address1" => $validated['address1'],
                    "address2" => $validated['address2'],
                    "city"     => $validated['city'],
                    "company"  => $validated['company'],
                    "country"  => $validated['country'],
                    "firstName"  => $validated['firstName'],
                    "lastName"  => $validated['lastName'],
                    "phone"    => $validated['phone'],
                    "province"    => $validated['province'],
                    "zip"      => $validated['zip'],
                ]
            ];

            return $this->shopify->storefrontApiRequest($query, $variables);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => 'Something went wrong.',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Delete customer address
     */
    public function deleteAddress(Request $request)
    {
        $customerAccessToken = $this->customerAccessToken;

        $query = <<<'GRAPHQL'
        mutation customerAddressDelete(
            $customerAccessToken: String!,
            $id: ID!
        ) {
            customerAddressDelete(customerAccessToken: $customerAccessToken, id: $id) {
                deletedCustomerAddressId
                customerUserErrors {
                    message
                }
            }
        }
        GRAPHQL;

        $variables = [
            "customerAccessToken" => $customerAccessToken,
            "id" => $request->address_id
        ];

        return $this->shopify->storefrontApiRequest($query, $variables);
    }
}
