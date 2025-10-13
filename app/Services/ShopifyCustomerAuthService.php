<?php

namespace App\Services;

use App\Services\APIShopifyService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ShopifyCustomerAuthService
{
    protected $api;

    public function __construct(APIShopifyService $api)
    {
        $this->api = $api;
    }

    /**
     * Create new customer (signup)
     * NOT WORKING - use (customers.json) REST API instead
     */
    public function signupCustomer($firstName, $lastName, $email, $password, $acceptsMarketing = false)
    {
        $query = <<<'GRAPHQL'
        mutation customerCreate($input: CustomerCreateInput!) {
            customerCreate(input: $input) {
                customer {
                    id
                    email
                    firstName
                    lastName
                }
                customerUserErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;

        $variables = [
            'input' => [
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'verified_email' => true,
                'password' => $password,
                'password_confirmation' => $password,
                'accepts_marketing' => $acceptsMarketing,
                'send_email_welcome' => true,
                'form_type' => 'create_customer',
            ],
        ];

        $response = $this->api->storefrontApiRequest($query, $variables);

        $errors = data_get($response, 'data.customerCreate.customerUserErrors', []);

        if (!empty($errors)) {
            // Log errors and return
            Log::warning('Shopify signup errors', $errors);
            return [
                'success' => false,
                'errors' => $errors,
            ];
        }

        // Signup success
        $customer = data_get($response, 'data.customerCreate.customer');
        if ($customer) {
            // Automatically login customer after signup
            $tokenData = $this->loginCustomer($email, $password);
            return array_merge(['success' => true, 'customer' => $customer], $tokenData ?? []);
        }

        return ['success' => false, 'errors' => [['message' => 'Unknown error during signup']]];
    }

    /**
     * Login customer and get access token
     */
    public function loginCustomer($email, $password)
    {
        $query = <<<'GRAPHQL'
        mutation customerAccessTokenCreate($input: CustomerAccessTokenCreateInput!) {
            customerAccessTokenCreate(input: $input) {
                customerAccessToken {
                    accessToken
                    expiresAt
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;

        $variables = ['input' => ['email' => $email, 'password' => $password]];

        $response = $this->api->storefrontApiRequest($query, $variables);

        if (!empty($response['data']['customerAccessTokenCreate']['customerAccessToken'])) {
            $tokenData = $response['data']['customerAccessTokenCreate']['customerAccessToken'];
            return [
                'access_token' => $tokenData['accessToken'],
                'expires_at' => Carbon::parse($tokenData['expiresAt']),
            ];
        }

        // Log errors
        if (!empty($response['data']['customerAccessTokenCreate']['userErrors'])) {
            Log::warning('Shopify login user errors', $response['data']['customerAccessTokenCreate']['userErrors']);
        }

        return null;
    }

    /**
     * Verify token validity
     */
    public function verifyToken($accessToken, $expiresAt = null)
    {
        // First, check local expiry
        if ($expiresAt && Carbon::now()->gt(Carbon::parse($expiresAt))) {
            return false;
        }

        $query = <<<'GRAPHQL'
        query($token: String!) {
            customer(customerAccessToken: $token) {
                id
                email
                firstName
                lastName
            }
        }
        GRAPHQL;

        $variables = ['token' => $accessToken];

        try {
            $response = $this->api->storefrontApiRequest($query, $variables);
            $customer = data_get($response, 'data.customer');

            return $customer ?: false;
        } catch (\Throwable $e) {
            Log::error('Shopify token verification failed', [
                'token' => $accessToken,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * Renew token if expired
     */
    public function renewToken($accessToken)
    {
        $query = <<<'GRAPHQL'
        mutation customerAccessTokenRenew($token: String!) {
            customerAccessTokenRenew(customerAccessToken: $token) {
                customerAccessToken {
                    accessToken
                    expiresAt
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;

        $variables = ['token' => $accessToken];

        $response = $this->api->storefrontApiRequest($query, $variables);

        if (!empty($response['data']['customerAccessTokenRenew']['customerAccessToken'])) {
            $tokenData = $response['data']['customerAccessTokenRenew']['customerAccessToken'];
            return [
                'access_token' => $tokenData['accessToken'],
                'expires_at' => Carbon::parse($tokenData['expiresAt']),
            ];
        }

        return null;
    }
}
