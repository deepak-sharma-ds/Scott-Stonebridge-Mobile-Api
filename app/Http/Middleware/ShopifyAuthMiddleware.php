<?php

namespace App\Http\Middleware;

use App\Contracts\Shopify\StorefrontApiClientInterface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * ShopifyAuthMiddleware
 * 
 * Validates Shopify customer access token and adds customer context to request.
 * Returns 401 on authentication failure.
 * 
 * Requirements: 15.4
 */
class ShopifyAuthMiddleware
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected StorefrontApiClientInterface $storefrontClient
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Extract bearer token from Authorization header
        $token = $request->bearerToken();
        
        if (!$token) {
            return $this->unauthorizedResponse('Missing authentication token');
        }

        // Optionally check token expiry from header (if provided by client)
        $expiresAt = $request->header('X-Token-Expires-At');
        
        if ($expiresAt && now()->gt($expiresAt)) {
            return $this->unauthorizedResponse('Token has expired');
        }

        // Verify token with Shopify
        $customer = $this->verifyToken($token);
        
        if (!$customer) {
            return $this->unauthorizedResponse('Invalid or expired token');
        }

        // Add customer data to request context
        $request->attributes->set('shopify_customer', $customer);
        $request->attributes->set('shopify_customer_id', $customer['id'] ?? null);
        
        // Also make it available via request merge for backward compatibility
        $request->merge([
            'shopify_customer_data' => $customer,
            'shopify_customer_id' => $customer['id'] ?? null,
        ]);

        // Trim request parameters
        $request->merge(
            array_map(function ($value) {
                return is_string($value) ? trim($value) : $value;
            }, $request->all())
        );

        return $next($request);
    }

    /**
     * Verify Shopify customer access token
     * 
     * @param string $accessToken
     * @return array|null Customer data or null if invalid
     */
    protected function verifyToken(string $accessToken): ?array
    {
        $log = Log::channel('api');
        
        try {
            $variables = ['customerAccessToken' => $accessToken];
            
            // Query Shopify Storefront API
            $response = $this->storefrontClient->query('storefront/customer/get_customer_profile', $variables);
            
            // Check for GraphQL errors
            if (isset($response['errors']) && !empty($response['errors'])) {
                $log->warning('Shopify token verification failed', [
                    'errors' => $response['errors'],
                ]);
                return null;
            }
            
            // Extract customer data
            $customer = $response['data']['customer'] ?? null;
            
            if (!$customer) {
                $log->warning('Shopify token verification returned no customer');
                return null;
            }
            
            $log->info('Shopify customer authenticated', [
                'customer_id' => $customer['id'] ?? null,
                'email' => $customer['email'] ?? null,
            ]);
            
            return $customer;
            
        } catch (\Exception $e) {
            $log->error('Shopify token verification exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Build unauthorized response
     */
    protected function unauthorizedResponse(string $message): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => [],
            'meta' => [
                'error_code' => 'UNAUTHORIZED',
            ],
        ], 401);
    }
}
