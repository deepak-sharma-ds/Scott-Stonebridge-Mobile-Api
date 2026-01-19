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
        $log = Log::channel('shopify_customers_auth');
        $log->info('================== START: ShopifyCustomerAuthService: loginCustomer ==================');
        $log->info('Attempting Shopify customer login', [
            'email' => $email,
        ]);
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
        $log->info('Shopify customer login response', $response);
        if (isset($response['errors'])) {
            return null;
        }

        if (!empty($response['data']['customerAccessTokenCreate']['customerAccessToken'])) {
            $tokenData = $response['data']['customerAccessTokenCreate']['customerAccessToken'];
            $log->info('Shopify customer login successful', [
                'email' => $email,
                'expires_at' => $tokenData['expiresAt'],
            ]);
            $log->info('================== END: ShopifyCustomerAuthService: loginCustomer ==================');
            return [
                'access_token' => $tokenData['accessToken'],
                'expires_at' => Carbon::parse($tokenData['expiresAt']),
            ];
        }

        // Log errors
        if (!empty($response['data']['customerAccessTokenCreate']['userErrors'])) {
            $log->warning('Shopify login user errors', $response['data']['customerAccessTokenCreate']['userErrors']);
        }

        return null;
    }

    /**
     * Verify token validity
     * Optionally check expiry time
     */
    public function verifyToken($accessToken, $expiresAt = null)
    {
        $log = Log::channel('shopify_customers_auth');
        $log->info('================== START: ShopifyCustomerAuthService: verifyToken ==================');
        // First, check local expiry
        if ($expiresAt && Carbon::now()->gt(Carbon::parse($expiresAt))) {
            $log->info('Shopify customer token expired locally', [
                'token' => $accessToken,
                'expires_at' => $expiresAt,
            ]);
            return false;
        }

        $query = <<<'GRAPHQL'
            query($token: String!) {
                customer(customerAccessToken: $token) {
                    id
                    email
                    firstName
                    lastName
                    phone
                    acceptsMarketing
                    tags
                    defaultAddress {
                        id
                        firstName
                        lastName
                        address1
                        address2
                        city
                        province
                        country
                        zip
                        phone
                    }
                    addresses(first: 10) {
                        edges {
                            node {
                            id
                            firstName
                            lastName
                            address1
                            address2
                            city
                            province
                            country
                            zip
                            phone
                            }
                        }
                    }
                }
            }
        GRAPHQL;

        $variables = ['token' => $accessToken];

        try {
            $response = $this->api->storefrontApiRequest($query, $variables);
            $log->info('Shopify token verification response', $response);
            if (isset($response['errors'])) {
                $log->warning('Shopify token verification errors', $response['errors']);
                return false;
            }
            $customer = data_get($response, 'data.customer');

            $log->info('================== END: ShopifyCustomerAuthService: verifyToken ==================');
            return $customer ?: false;
        } catch (\Throwable $e) {
            $log->error('Shopify token verification failed', [
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
        $log = Log::channel('shopify_customers_auth');
        $log->info('================== START: ShopifyCustomerAuthService: renewToken ==================');

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
        $log->info('Shopify token renewal response', $response);
        if (isset($response['errors'])) {
            $log->warning('Shopify token renewal errors', $response['errors']);
            return null;
        }

        if (!empty($response['data']['customerAccessTokenRenew']['customerAccessToken'])) {
            $tokenData = $response['data']['customerAccessTokenRenew']['customerAccessToken'];
            $log->info('Shopify token renewal successful', [
                'expires_at' => $tokenData['expiresAt'],
            ]);
            $log->info('================== END: ShopifyCustomerAuthService: renewToken ==================');
            return [
                'access_token' => $tokenData['accessToken'],
                'expires_at' => Carbon::parse($tokenData['expiresAt']),
            ];
        }

        $log->warning('Shopify token renewal user errors', $response['data']['customerAccessTokenRenew']['userErrors'] ?? []);
        return null;
    }

    /**
     * Sends a password reset email to the customer.
     */
    public function sendPasswordResetEmail(string $email)
    {
        $log = Log::channel('shopify_customers_auth');
        $log->info('================== START: ShopifyCustomerAuthService: sendPasswordResetEmail ==================');

        $query = <<<'GRAPHQL'
            mutation customerRecover($email: String!) {
                customerRecover(email: $email) {
                    customerUserErrors {
                        field
                        message
                    }
                }
            }
            GRAPHQL;

        $variables = ['email' => $email];

        $response = $this->api->storefrontApiRequest($query, $variables);
        $log->info('Shopify password reset email response', $response);
        if (isset($response['errors'])) {
            $log->error('Shopify password reset email failed', [
                'email' => $email,
                'errors' => $response['errors'],
            ]);
            return [
                'success' => false,
                'message' => 'Unknown error occurred',
                'error' => $response['errors'] ?? []
            ];
        }

        if (!empty($response['data']['customerRecover']['customerUserErrors'])) {
            $log->warning('Shopify password reset email user errors', $response['data']['customerRecover']['customerUserErrors']);
            return [
                'success' => false,
                'message' => $response['data']['customerRecover']['customerUserErrors']
            ];
        }

        $log->info('Shopify password reset email sent successfully', ['email' => $email]);
        $log->info('================== END: ShopifyCustomerAuthService: sendPasswordResetEmail ==================');
        return [
            'success' => true,
            'message' => 'Password reset email sent successfully.'
        ];
    }

    /**
     * Resets customer password using Shopify token.
     */
    public function resetPassword(string $resetUrl, string $newPassword)
    {
        $log = Log::channel('shopify_customers_auth');
        $log->info('================== START: ShopifyCustomerAuthService: resetPassword ==================');

        $query = <<<'GRAPHQL'
            mutation customerResetByUrl($resetUrl: URL!, $password: String!) {
                customerResetByUrl(resetUrl: $resetUrl, password: $password) {
                    customer {
                        id
                        email
                    }
                    customerAccessToken {
                        accessToken
                        expiresAt
                    }
                    customerUserErrors {
                        field
                        message
                    }
                }
            }
            GRAPHQL;

        $variables = [
            'resetUrl' => $resetUrl,
            'password' => $newPassword,
        ];

        $response = $this->api->storefrontApiRequest($query, $variables);
        $log->info('Shopify password reset response', $response);
        if (isset($response['errors'])) {
            $log->error('Shopify password reset failed', [
                'reset_url' => $resetUrl,
                'errors' => $response['errors'],
            ]);
            return [
                'success' => false,
                'message' => 'Unknown error occurred',
                'error' => $response['errors'] ?? []
            ];
        }

        $data = $response['data']['customerResetByUrl'] ?? null;

        if (!empty($data['customerUserErrors'])) {
            $log->warning('Shopify password reset user errors', $data['customerUserErrors']);
            return [
                'success' => false,
                'message' => $data['customerUserErrors']
            ];
        }

        $log->info('Shopify password reset successful', [
            'customer_id' => $data['customer']['id'] ?? null,
            'email' => $data['customer']['email'] ?? null,
        ]);
        $log->info('================== END: ShopifyCustomerAuthService: resetPassword ==================');
        return [
            'success' => true,
            'message' => 'Password has been reset successfully.',
            'access_token' => $data['customerAccessToken']['accessToken'] ?? null,
            'expires_at' => $data['customerAccessToken']['expiresAt'] ?? null,
        ];
    }

    /**
     * Logout customer by revoking access token
     */
    public function logoutCustomer(string $accessToken): bool
    {
        $log = Log::channel('shopify_customers_auth');
        $log->info('================== START: ShopifyCustomerAuthService: logoutCustomer ==================');

        if (empty($accessToken)) {
            $log->warning('Logout failed: Access token missing');
            return false;
        }

        $query = <<<'GRAPHQL'
            mutation customerAccessTokenDelete($token: String!) {
                customerAccessTokenDelete(customerAccessToken: $token) {
                    deletedAccessToken
                    userErrors {
                        field
                        message
                    }
                }
            }
        GRAPHQL;

        $variables = [
            'token' => $accessToken,
        ];

        try {
            $response = $this->api->storefrontApiRequest($query, $variables);
            $log->info('Shopify logout response', $response);

            if (isset($response['errors'])) {
                $log->error('Shopify logout failed', $response['errors']);
                return false;
            }

            $errors = data_get(
                $response,
                'data.customerAccessTokenDelete.userErrors',
                []
            );

            if (!empty($errors)) {
                $log->warning('Shopify logout user errors', $errors);
                return false;
            }

            $log->info('Shopify logout successful', [
                'token' => $accessToken,
            ]);

            $log->info('================== END: ShopifyCustomerAuthService: logoutCustomer ==================');
            return true;
        } catch (\Throwable $e) {
            $log->error('Shopify logout exception', [
                'token' => $accessToken,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
