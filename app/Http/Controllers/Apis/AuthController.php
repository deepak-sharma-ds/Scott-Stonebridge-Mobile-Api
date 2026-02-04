<?php

namespace App\Http\Controllers\Apis;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\V1\ProductResource; // Assuming we might need this later, but not here
use App\Services\ShopifyCustomerAuthService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ShopifyCustomerAuthService $authService
    ) {}

    /**
     * Register new customer
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(RegisterRequest $request)
    {
        $data = $request->validated();

        $response = $this->authService->signupCustomer(
            firstName: $data['first_name'],
            lastName: $data['last_name'] ?? '',
            email: $data['email'],
            password: $data['password'],
            acceptsMarketing: $data['subscribe'] ?? false
        );

        if (!$response['success']) {
            return $this->error(
                'Failed to register customer', 
                $response['errors'], 
                400
            );
        }

        return $this->success(
            'Customer registered successfully',
            $response, // Contains customer DTO and token
            201
        );
    }

    /**
     * Login customer and return access token
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(LoginRequest $request)
    {
        $data = $request->validated();
        
        $tokenData = $this->authService->loginCustomer(
            email: $data['email'],
            password: $data['password']
        );

        if (!$tokenData) {
            return $this->error('Invalid credentials', null, 401);
        }

        return $this->success(
            'Login successful',
            [
                'access_token' => $tokenData['access_token'],
                'expires_at' => is_a($tokenData['expires_at'], \Carbon\Carbon::class) 
                    ? $tokenData['expires_at']->toDateTimeString() 
                    : $tokenData['expires_at'],
            ]
        );
    }

    /**
     * Step 1: Send password reset email
     */
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email'
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $email = $request->input('email');

        $response = $this->authService->sendPasswordResetEmail($email);

        if ($response['success']) {
            return $this->success(
                'Password reset email sent if the email exists in our system'
            );
        }

        return $this->error(
            'Failed to send password reset email. Please check the email address.',
            $response['message'] ?? [],
            400
        );
    }

    /**
     * Step 2: Reset password using token from email
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reset_token' => 'required|string', // This is the reset URL usually
            'new_password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $response = $this->authService->resetPassword(
            resetUrl: $request->input('reset_token'), // API usually sends simple token; Shopify needs URL? 
            // The previous implementation named it 'reset_token' but service expected 'resetUrl'.
            // Assuming the frontend sends the full reset URL provided in the email link?
            // Or if it sends a token, we might need to construct the URL?
            // Shopify storefront API requires `resetUrl`.
            // Let's assume input is correct for now based on previous code logic.
            newPassword: $request->input('new_password')
        );

        if ($response['success']) {
            return $this->success(
                $response['message'] ?? 'Password has been reset successfully',
                [
                    'access_token' => $response['access_token'] ?? null,
                    'expires_at' => $response['expires_at'] ?? null,
                ]
            );
        }

        return $this->error(
            'Failed to reset password',
            $response['message'] ?? [],
            400
        );
    }

    /**
     * Get authenticated customer profile
     */
    public function getProfile(Request $request)
    {
        $token = $request->bearerToken();
        $expiresAt = $request->header('X-Token-Expires-At');

        $customer = $this->authService->verifyToken($token, $expiresAt);

        if (!$customer) {
            return $this->error('Token invalid or expired', null, 401);
        }

        // Return DTO possibly wrapped in resource or as array
        return $this->success(
            'Profile retrieved successfully',
            ['customer' => $customer]
        );
    }

    /**
     * Logout customer and revoke access token
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        $accessToken = $request->bearerToken();

        if (!$accessToken) {
            return $this->error('Access token missing', null, 401);
        }

        $loggedOut = $this->authService->logoutCustomer($accessToken);

        if (!$loggedOut) {
            return $this->error('Failed to logout', null, 400);
        }

        return $this->success('Logout successful');
    }
}
