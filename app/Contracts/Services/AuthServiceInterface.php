<?php

namespace App\Contracts\Services;

use App\DTOs\Customer\CustomerDTO;

interface AuthServiceInterface
{
    /**
     * Authenticate customer with email and password
     *
     * @param string $email Customer email
     * @param string $password Customer password
     * @return array ['customer' => CustomerDTO, 'access_token' => string]
     */
    public function login(string $email, string $password): array;

    /**
     * Register a new customer
     *
     * @param array $data Customer registration data
     * @return array ['customer' => CustomerDTO, 'access_token' => string]
     */
    public function register(array $data): array;

    /**
     * Initiate password reset
     *
     * @param string $email Customer email
     * @return bool
     */
    public function forgotPassword(string $email): bool;

    /**
     * Reset customer password
     *
     * @param string $resetToken Password reset token
     * @param string $password New password
     * @return bool
     */
    public function resetPassword(string $resetToken, string $password): bool;

    /**
     * Logout customer (invalidate token if needed)
     *
     * @param string $accessToken Customer access token
     * @return bool
     */
    public function logout(string $accessToken): bool;

    /**
     * Suspend customer account
     *
     * @param array $shopifyCustomerData Shopify customer data
     * @return bool
     */
    public function suspend(array $shopifyCustomerData): bool;
}
