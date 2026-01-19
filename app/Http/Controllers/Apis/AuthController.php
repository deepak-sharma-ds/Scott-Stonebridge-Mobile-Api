<?php

namespace App\Http\Controllers\Apis;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;
use App\Services\APIShopifyService;
use App\Services\ShopifyCustomerAuthService;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    protected $shopify;
    protected $authService;

    public function __construct(APIShopifyService $shopify, ShopifyCustomerAuthService $authService)
    {
        $this->shopify = $shopify;
        $this->authService = $authService;
    }

    /**
     * Register new customer
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        // Validate incoming request
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'first_name' => 'required|string',
            'last_name' => 'nullable|string',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        try {
            $data['password_confirmation'] = $data['password'];
            $response = $this->shopify->createCustomer([
                'customer' => [
                    'email' => $data['email'],
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'] ?? '',
                    'verified_email' => true,
                    'password' => $data['password'],
                    'password_confirmation' => $data['password_confirmation'],
                    'accepts_marketing' =>  $data['subscribe'] ?? false,
                    'send_email_welcome' => true,
                    'form_type' => 'create_customer',
                ],
            ]);

            if (isset($response['customer'])) {
                return response()->json(['message' => 'Customer registered successfully', 'customer' => $response['customer']], 201);
            }

            // If Shopify API returns error response but no exception thrown
            return response()->json(['error' => 'Failed to register customer', 'details' => $response], 400);
        } catch (\Throwable $th) {
            // Return the error message from the exception
            return response()->json([
                'error' => 'Failed to register customer',
                'message' => $th->getMessage()
            ], 400);
        }
    }

    /**
     * Login customer and return access token
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $tokenData = $this->authService->loginCustomer($request->email, $request->password);

        if (!$tokenData) {
            return response()->json(['status' => 401, 'message' => 'Invalid credentials'], 401);
        }

        return response()->json([
            'status' => 200,
            'message' => 'Login successful',
            'data' => [
                'access_token' => $tokenData['access_token'],
                'expires_at' => $tokenData['expires_at']->toDateTimeString(),
            ],
        ]);



        // $response = $this->shopify->customerAccessTokenCreate($data['email'], $data['password']);

        // if ($response['success']) {
        //     return response()->json([
        //         'accessToken' => $response['token'],
        //         'expiresAt' => $response['expiresAt'],
        //     ]);
        // }

        // return response()->json([
        //     'errors' => $response['errors']
        // ], 401);
    }

    /**
     * Step 1: Send password reset email
     * Shopify will handle the email and token generation
     */
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $email = $validator->validated()['email'];

        try {
            $response = $this->authService->sendPasswordResetEmail($email);

            if (!empty($response['success']) && $response['success']) {
                return response()->json([
                    'status' => 200,
                    'message' => 'Password reset email sent if the email exists in our system'
                ], 200);
            }

            return response()->json([
                'status' => 400,
                'message' => 'Failed to send password reset email. Please check the email address.',
                'error' => $response['error'] ?? []
            ], 400);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => 'Unexpected error',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Step 2: Reset password using token from email
     * Shopify will automatically reset from website, here we just create the API not using it.
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reset_token' => 'required|string',
            'new_password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        try {
            $response = $this->authService->resetPassword($data['reset_token'], $data['new_password']);

            if (!empty($response['success']) && $response['success']) {
                return response()->json([
                    'status' => 200,
                    'message' => $response['message'] ?? 'Password has been reset successfully',
                    'data' => [
                        'access_token' => $response['access_token'] ?? null,
                        'expires_at' => $response['expires_at'] ?? null,
                    ],
                ], 200);
            }

            return response()->json([
                'status' => 400,
                'message' => 'Failed to reset password',
                'error' => $response['error'] ?? []
            ], 400);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => 'Unexpected error',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Get authenticated customer profile
     */
    public function getProfile(Request $request)
    {
        try {
            $token = $request->bearerToken();
            $expiresAt = $request->header('X-Token-Expires-At');

            $customer = $this->authService->verifyToken($token, $expiresAt);

            if (!$customer) {
                return response()->json(['status' => 401, 'message' => 'Token invalid or expired'], 401);
            }

            return response()->json([
                'status' => 200,
                'message' => 'Profile retrieved successfully',
                'data' => ['customer' => $customer]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => 'Internal Server Error',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Logout customer and revoke access token
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        $accessToken = $request->bearerToken();

        if (!$accessToken) {
            return response()->json([
                'status' => 401,
                'message' => 'Access token missing',
            ], 401);
        }

        $loggedOut = $this->authService->logoutCustomer($accessToken);

        if (!$loggedOut) {
            return response()->json([
                'status' => 400,
                'message' => 'Failed to logout',
            ], 400);
        }

        return response()->json([
            'status' => 200,
            'message' => 'Logout successful',
        ], 200);
    }
}
