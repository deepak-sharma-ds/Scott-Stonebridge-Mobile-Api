<?php

namespace App\Services\Shopify;

use App\Contracts\Services\AuthServiceInterface;
use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\DTOs\Customer\CustomerDTO;
use App\Services\Base\BaseService;
use App\Exceptions\ShopifyApiException;
use App\Exceptions\ShopifyAuthException;

class AuthService extends BaseService implements AuthServiceInterface
{
    public function __construct(
        protected StorefrontApiClientInterface $storefrontClient
    ) {
        parent::__construct();
    }

    /**
     * Authenticate customer with email and password
     *
     * @param string $email Customer email
     * @param string $password Customer password
     * @return array ['customer' => CustomerDTO, 'access_token' => string]
     */
    public function login(string $email, string $password): array
    {
        try {
            $this->logPerformanceStart('login');

            $variables = [
                'input' => [
                    'email' => $email,
                    'password' => $password,
                ],
            ];

            $response = $this->storefrontClient->query('storefront/auth/customer_login', $variables);

            if (!empty($response['data']['customerAccessTokenCreate']['customerUserErrors'])) {
                $errors = $response['data']['customerAccessTokenCreate']['customerUserErrors'];
                $errorMessage = $this->formatCustomerErrors($errors);
                throw new ShopifyAuthException($errorMessage);
            }

            if (empty($response['data']['customerAccessTokenCreate']['customerAccessToken'])) {
                throw new ShopifyAuthException('Login failed: Invalid credentials');
            }

            $accessToken = $response['data']['customerAccessTokenCreate']['customerAccessToken']['accessToken'];

            // Fetch customer profile
            $customerResponse = $this->storefrontClient->query('storefront/customer/get_customer_profile', [
                'customerAccessToken' => $accessToken,
            ]);

            if (empty($customerResponse['data']['customer'])) {
                throw new ShopifyAuthException('Failed to fetch customer profile after login');
            }

            $customer = CustomerDTO::fromShopifyResponse($customerResponse['data']['customer']);

            $this->logPerformanceEnd('login', [
                'customer_id' => $customer->id,
                'email' => $email,
            ]);

            return [
                'customer' => $customer,
                'access_token' => $accessToken,
            ];
        } catch (\Exception $e) {
            $this->logErrorWithException('Login failed', $e, ['email' => $email]);
            throw $e;
        }
    }

    /**
     * Register a new customer
     *
     * @param array $data Customer registration data
     * @return array ['customer' => CustomerDTO, 'access_token' => string]
     */
    public function register(array $data): array
    {
        try {
            $this->logPerformanceStart('register');

            $customerInput = [
                'email' => $data['email'],
                'password' => $data['password'],
            ];

            if (isset($data['firstName'])) {
                $customerInput['firstName'] = $data['firstName'];
            }
            if (isset($data['lastName'])) {
                $customerInput['lastName'] = $data['lastName'];
            }
            if (isset($data['phone'])) {
                $customerInput['phone'] = $data['phone'];
            }
            if (isset($data['acceptsMarketing'])) {
                $customerInput['acceptsMarketing'] = $data['acceptsMarketing'];
            }

            $variables = ['input' => $customerInput];

            $response = $this->storefrontClient->query('storefront/auth/customer_register', $variables);

            if (!empty($response['data']['customerCreate']['customerUserErrors'])) {
                $errors = $response['data']['customerCreate']['customerUserErrors'];
                throw new ShopifyApiException('Registration failed: ' . json_encode($errors));
            }

            if (empty($response['data']['customerCreate']['customer'])) {
                throw new ShopifyApiException('Registration failed: Empty response');
            }

            // After registration, log the customer in
            $loginResult = $this->login($data['email'], $data['password']);

            $this->logPerformanceEnd('register', [
                'customer_id' => $loginResult['customer']->id,
                'email' => $data['email'],
            ]);

            return $loginResult;
        } catch (\Exception $e) {
            $this->logErrorWithException('Registration failed', $e, ['email' => $data['email'] ?? null]);
            throw $e;
        }
    }

    /**
     * Initiate password reset
     *
     * @param string $email Customer email
     * @return bool
     */
    public function forgotPassword(string $email): bool
    {
        try {
            $this->logPerformanceStart('forgotPassword');

            $variables = ['email' => $email];

            $response = $this->storefrontClient->query('storefront/auth/customer_recover', $variables);

            if (!empty($response['data']['customerRecover']['customerUserErrors'])) {
                $errors = $response['data']['customerRecover']['customerUserErrors'];
                // throw new ShopifyApiException('Password recovery failed: ' . json_encode($errors));
                throw new ShopifyApiException(
                    'Password recovery failed',
                    0,
                    null,
                    ['shopify_errors' => $errors]
                );
            }

            $this->logPerformanceEnd('forgotPassword', ['email' => $email]);

            return true;
        } catch (\Exception $e) {
            $this->logErrorWithException('Password recovery failed', $e, ['email' => $email]);
            throw $e;
        }
    }

    /**
     * Reset customer password
     *
     * @param string $resetToken Password reset token (format: customerId/resetToken)
     * @param string $password New password
     * @return bool
     */
    public function resetPassword(string $resetToken, string $password): bool
    {
        try {
            $this->logPerformanceStart('resetPassword');

            // Parse the reset token to extract customer ID and token
            // Shopify format: "gid://shopify/Customer/123456789/resetToken"
            $parts = explode('/', $resetToken);
            $customerId = $parts[0] ?? null;
            $token = $parts[1] ?? $resetToken;

            if (!$customerId) {
                throw new ShopifyApiException('Invalid reset token format');
            }

            $variables = [
                'id' => $customerId,
                'input' => [
                    'resetToken' => $token,
                    'password' => $password,
                ],
            ];

            $response = $this->storefrontClient->query('storefront/auth/customer_reset', $variables);

            if (!empty($response['data']['customerReset']['customerUserErrors'])) {
                $errors = $response['data']['customerReset']['customerUserErrors'];
                throw new ShopifyApiException('Password reset failed: ' . json_encode($errors));
            }

            $this->logPerformanceEnd('resetPassword');

            return true;
        } catch (\Exception $e) {
            $this->logErrorWithException('Password reset failed', $e);
            throw $e;
        }
    }

    /**
     * Logout customer (invalidate token if needed)
     *
     * @param string $accessToken Customer access token
     * @return bool
     */
    public function logout(string $accessToken): bool
    {
        try {
            $this->logPerformanceStart('logout');

            $variables = ['customerAccessToken' => $accessToken];

            $response = $this->storefrontClient->query('storefront/auth/customer_logout', $variables);

            if (!empty($response['data']['customerAccessTokenDelete']['userErrors'])) {
                $errors = $response['data']['customerAccessTokenDelete']['userErrors'];
                throw new ShopifyApiException('Logout failed: ' . json_encode($errors));
            }

            $deleted = !empty($response['data']['customerAccessTokenDelete']['deletedAccessToken']);

            $this->logPerformanceEnd('logout', ['deleted' => $deleted]);

            return $deleted;
        } catch (\Exception $e) {
            $this->logErrorWithException('Logout failed', $e);
            throw $e;
        }
    }

    /**
     * Format customer error messages for better readability
     *
     * @param array $errors
     * @return string
     */
    private function formatCustomerErrors(array $errors): string
    {
        if (empty($errors)) {
            return 'Unknown error occurred';
        }

        $messages = [];
        foreach ($errors as $error) {
            $code = $error['code'] ?? 'UNKNOWN';
            $message = $error['message'] ?? 'Unknown error';

            // Provide user-friendly messages for common errors
            switch ($code) {
                case 'UNIDENTIFIED_CUSTOMER':
                    $messages[] = 'Invalid email or password. Please check your credentials or register a new account.';
                    break;
                case 'CUSTOMER_DISABLED':
                    $messages[] = 'This customer account has been disabled. Please contact support.';
                    break;
                default:
                    $messages[] = $message;
            }
        }

        return implode(' ', $messages);
    }
}
