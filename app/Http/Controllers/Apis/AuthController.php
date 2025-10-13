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

    public function __construct(APIShopifyService $shopify)
    {
        $this->shopify = $shopify;
    }

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

    public function login(Request $request, ShopifyCustomerAuthService $authService)
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
        $tokenData = $authService->loginCustomer($request->email, $request->password);

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

    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), ['email' => 'required|email']);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        try {
            $response = $this->shopify->sendPasswordResetEmail($data['email']);

            // Extract errors from response
            $errors = $response['data']['customerRecover']['customerUserErrors'] ?? [];

            if (empty($errors)) {
                return response()->json(['message' => 'Password reset email sent if the email exists in our system']);
            }

            return response()->json([
                'error' => 'Failed to send password reset email',
                'details' => $errors
            ], 400);
        } catch (\Throwable $th) {
            return response()->json([
                'error' => 'Unexpected error',
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function resetPassword(Request $request)
    {
        
    }
}