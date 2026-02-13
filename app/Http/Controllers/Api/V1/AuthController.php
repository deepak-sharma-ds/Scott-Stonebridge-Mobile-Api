<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\AuthServiceInterface;
use App\Contracts\Services\CustomerServiceInterface;
use App\Http\Controllers\Base\BaseApiController;
use App\Http\Resources\Customer\CustomerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Auth Controller (v1)
 * 
 * Handles authentication-related API endpoints.
 * Manages customer login, registration, and profile access.
 * Extends BaseApiController for standardized responses.
 * 
 * Requirements: 2.1, 2.2, 5.4, 11.6
 */
class AuthController extends BaseApiController
{
    public function __construct(
        protected AuthServiceInterface $authService,
        protected CustomerServiceInterface $customerService
    ) {}

    /**
     * Customer login
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function login(Request $request): JsonResponse
    {
        try {
            $email = $request->input('email');
            $password = $request->input('password');

            if (empty($email)) {
                return $this->validationError(
                    'Validation failed',
                    ['email' => ['The email field is required']]
                );
            }

            if (empty($password)) {
                return $this->validationError(
                    'Validation failed',
                    ['password' => ['The password field is required']]
                );
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->validationError(
                    'Validation failed',
                    ['email' => ['The email must be a valid email address']]
                );
            }

            $result = $this->authService->login($email, $password);

            return $this->success(
                'Login successful',
                [
                    'customer' => new CustomerResource($result['customer']),
                    'access_token' => $result['access_token'],
                ]
            );
        } catch (\App\Exceptions\ShopifyAuthException $e) {
            return $this->unauthorized($e->getMessage());
        } catch (\Exception $e) {
            return $this->error(
                'Login failed',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }

    /**
     * Customer registration
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function register(Request $request): JsonResponse
    {
        try {
            $email = $request->input('email');
            $password = $request->input('password');
            $firstName = $request->input('first_name');
            $lastName = $request->input('last_name');
            $phone = $request->input('phone');
            $acceptsMarketing = $request->boolean('accepts_marketing', false);

            // Validation
            $errors = [];

            if (empty($email)) {
                $errors['email'] = ['The email field is required'];
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = ['The email must be a valid email address'];
            }

            if (empty($password)) {
                $errors['password'] = ['The password field is required'];
            } elseif (strlen($password) < 8) {
                $errors['password'] = ['The password must be at least 8 characters'];
            }

            if (!empty($errors)) {
                return $this->validationError('Validation failed', $errors);
            }

            $data = [
                'email' => $email,
                'password' => $password,
                'firstName' => $firstName,
                'lastName' => $lastName,
                'phone' => $phone,
                'acceptsMarketing' => $acceptsMarketing,
            ];

            $result = $this->authService->register($data);

            return $this->success(
                'Registration successful',
                [
                    'customer' => new CustomerResource($result['customer']),
                    'access_token' => $result['access_token'],
                ],
                [],
                201
            );
        } catch (\App\Exceptions\ShopifyApiException $e) {
            return $this->error(
                'Registration failed',
                ['error' => $e->getMessage()],
                [],
                422
            );
        } catch (\Exception $e) {
            return $this->error(
                'Registration failed',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }

    /**
     * Get current customer profile
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function me(Request $request): JsonResponse
    {
        try {
            $accessToken = $request->input('access_token') ?? $request->bearerToken();

            if (empty($accessToken)) {
                return $this->unauthorized('Access token is required');
            }

            $customer = $this->customerService->getCustomer($accessToken);

            return $this->success(
                'Customer profile fetched successfully',
                [
                    'customer' => new CustomerResource($customer),
                ]
            );
        } catch (\App\Exceptions\ShopifyNotFoundException $e) {
            return $this->unauthorized($e->getMessage());
        } catch (\Exception $e) {
            return $this->error(
                'Failed to fetch customer profile',
                ['error' => $e->getMessage()],
                [],
                500
            );
        }
    }
}

