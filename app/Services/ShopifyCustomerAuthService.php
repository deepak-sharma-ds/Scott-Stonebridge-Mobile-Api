<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Shopify\ShopifyAdapterInterface;
use App\DTOs\Shopify\CustomerDTO;
use App\Services\Shopify\GraphQLLoaderService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ShopifyCustomerAuthService
{
    public function __construct(
        private readonly ShopifyAdapterInterface $adapter,
        private readonly GraphQLLoaderService $queryLoader
    ) {}

    /**
     * Create new customer (signup)
     */
    public function signupCustomer(string $firstName, string $lastName, string $email, string $password, bool $acceptsMarketing = false): array
    {
        $query = $this->queryLoader->load('storefront/customers/create');

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

        $response = $this->adapter->storefrontQuery($query, $variables);

        // Handle User Errors (functional errors from Shopify)
        $errors = data_get($response, 'customerCreate.customerUserErrors', []);

        if (! empty($errors)) {
            return [
                'success' => false,
                'errors' => $errors,
            ];
        }

        $customerData = data_get($response, 'customerCreate.customer');

        if ($customerData) {
            // Parse to DTO
            $customerDTO = CustomerDTO::fromShopifyNode($customerData);

            // Dispatch Event
            \App\Events\CustomerRegistered::dispatch($customerDTO);

            return [
                'success' => true,
                'customer' => $customerDTO,
            ];
        }

        return ['success' => false, 'errors' => [['message' => 'Unknown error during registration']]];
    }

    /**
     * Login customer and get access token
     */
    public function loginCustomer(string $email, string $password): ?array
    {
        $query = $this->queryLoader->load('storefront/customers/access_token_create');

        $variables = ['input' => ['email' => $email, 'password' => $password]];

        $response = $this->adapter->storefrontQuery($query, $variables);

        $tokenData = data_get($response, 'customerAccessTokenCreate.customerAccessToken');

        if ($tokenData) {
            return [
                'access_token' => $tokenData['accessToken'],
                'expires_at' => Carbon::parse($tokenData['expiresAt']),
            ];
        }

        // Log user errors
        $userErrors = data_get($response, 'customerAccessTokenCreate.userErrors', []);
        if (! empty($userErrors)) {
            Log::warning('Shopify login user errors', $userErrors);
        }

        return null;
    }

    /**
     * Verify token validity and return Customer DTO
     */
    public function verifyToken(string $accessToken, ?string $expiresAt = null): ?CustomerDTO
    {
        // First, check local expiry check if provided
        if ($expiresAt && Carbon::now()->gt(Carbon::parse($expiresAt))) {
            return null;
        }

        $query = $this->queryLoader->load('storefront/customers/get_customer');
        $variables = ['token' => $accessToken];

        $response = $this->adapter->storefrontQuery($query, $variables);
        $customerData = data_get($response, 'customer');

        if (! $customerData) {
            return null;
        }

        return CustomerDTO::fromShopifyNode($customerData);
    }

    /**
     * Renew token if expired
     */
    public function renewToken(string $accessToken): ?array
    {
        $query = $this->queryLoader->load('storefront/customers/access_token_renew');
        $variables = ['token' => $accessToken];

        $response = $this->adapter->storefrontQuery($query, $variables);

        $tokenData = data_get($response, 'customerAccessTokenRenew.customerAccessToken');
        if ($tokenData) {
            return [
                'access_token' => $tokenData['accessToken'],
                'expires_at' => Carbon::parse($tokenData['expiresAt']),
            ];
        }

        return null;
    }

    /**
     * Sends a password reset email
     */
    public function sendPasswordResetEmail(string $email): array
    {
        $query = $this->queryLoader->load('storefront/customers/recover');
        $variables = ['email' => $email];

        $response = $this->adapter->storefrontQuery($query, $variables);

        $errors = data_get($response, 'customerRecover.customerUserErrors', []);

        if (! empty($errors)) {
            return [
                'success' => false,
                'message' => $errors,
            ];
        }

        return [
            'success' => true,
            'message' => 'Password reset email sent successfully.',
        ];
    }

    /**
     * Resets customer password using Shopify token.
     */
    public function resetPassword(string $resetUrl, string $newPassword): array
    {
        $query = $this->queryLoader->load('storefront/customers/reset_by_url');

        $variables = [
            'resetUrl' => $resetUrl,
            'password' => $newPassword,
        ];

        $response = $this->adapter->storefrontQuery($query, $variables);

        $data = data_get($response, 'customerResetByUrl', []);

        if (! empty($data['customerUserErrors'])) {
            return [
                'success' => false,
                'message' => $data['customerUserErrors'],
            ];
        }

        return [
            'success' => true,
            'message' => 'Password has been reset successfully.',
            'access_token' => data_get($data, 'customerAccessToken.accessToken'),
            'expires_at' => data_get($data, 'customerAccessToken.expiresAt'),
        ];
    }

    /**
     * Logout customer by revoking access token
     */
    public function logoutCustomer(string $accessToken): bool
    {
        $query = $this->queryLoader->load('storefront/customers/access_token_delete');
        $variables = ['token' => $accessToken];

        $response = $this->adapter->storefrontQuery($query, $variables);

        $deletedToken = data_get($response, 'customerAccessTokenDelete.deletedAccessToken');

        return ! empty($deletedToken);
    }
}
