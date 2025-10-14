<?php

namespace App\Http\Middleware;

use App\Services\APIShopifyService;
use App\Services\ShopifyCustomerAuthService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ShopifyCustomerAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $expiresAt = $request->header('X-Token-Expires-At');
        if (!$token) {
            return response()->json(['error' => 'Unauthorized Customer'], 401);
        }

        // Optionally call Shopify GraphQL to verify token
        $verified = $this->verifyToken($token, $expiresAt);
        if (!$verified) {
            return response()->json(['error' => 'Invalid token'], 401);
        }

        return $next($request);
    }

    /**
     * Check if the provided customer access token is valid. Also attaches customer data to request.
     * @return bool
     */
    // protected function verifyToken(string $customerAccessToken): bool
    // {
    //     $query = <<<'GRAPHQL'
    //         query($token: String!) {
    //             customer(customerAccessToken: $token) {
    //                 id
    //                 firstName
    //                 lastName
    //                 acceptsMarketing
    //                 email
    //                 phone
    //             }
    //         }
    //         GRAPHQL;
    //     // $query = <<<'GRAPHQL'
    //     //         query($token: String!) {
    //     //             customer(customerAccessToken: $token) {
    //     //                 id
    //     //                 firstName
    //     //                 lastName
    //     //                 email
    //     //                 phone
    //     //                 acceptsMarketing
    //     //                 createdAt
    //     //                 updatedAt
    //     //                 defaultAddress {
    //     //                 firstName
    //     //                 lastName
    //     //                 company
    //     //                 address1
    //     //                 address2
    //     //                 city
    //     //                 province
    //     //                 country
    //     //                 zip
    //     //                 phone
    //     //                 }
    //     //                 addresses(first: 10) {
    //     //                     edges {
    //     //                         node {
    //     //                         id
    //     //                         firstName
    //     //                         lastName
    //     //                         company
    //     //                         address1
    //     //                         address2
    //     //                         city
    //     //                         province
    //     //                         country
    //     //                         zip
    //     //                         phone
    //     //                         }
    //     //                     }
    //     //                 }
    //     //                 orders(first: 10) {
    //     //                     edges {
    //     //                         node {
    //     //                         id
    //     //                         name
    //     //                         orderNumber
    //     //                         processedAt
    //     //                         totalPriceV2 {
    //     //                             amount
    //     //                             currencyCode
    //     //                         }
    //     //                         lineItems(first: 5) {
    //     //                             edges {
    //     //                             node {
    //     //                                 title
    //     //                                 quantity
    //     //                                 variant {
    //     //                                 id
    //     //                                 sku
    //     //                                 priceV2 {
    //     //                                     amount
    //     //                                     currencyCode
    //     //                                 }
    //     //                                 }
    //     //                             }
    //     //                             }
    //     //                         }
    //     //                         }
    //     //                     }
    //     //                 }
    //     //             }
    //     //         }
    //     //         GRAPHQL;


    //     $variables = ['token' => $customerAccessToken];

    //     try {
    //         $apiService = new APIShopifyService();
    //         $response = $apiService->storefrontApiRequest($query, $variables);
    //         // Check for customer data
    //         if (!empty($response['data']['customer'])) {
    //             // Attach customer data to request for later use
    //             request()->merge(['shopify_customer_data' => $response['data']['customer']]);
    //             return true;
    //         }
    //     } catch (\Throwable $e) {
    //         // Log error for debugging
    //         Log::channel('shopify_customers_auth')->error('Shopify token verification failed', [
    //             'token' => $customerAccessToken,
    //             'error' => $e->getMessage(),
    //         ]);
    //     }

    //     return false;
    // }

    protected function verifyToken(string $accessToken, $expiresAt): bool
    {
        $log = Log::channel('shopify_customers_auth');
        $log->info('Verifying Shopify customer token', [
            'token' => $accessToken,
            'expires_at' => $expiresAt,
        ]);
        $authService = new ShopifyCustomerAuthService(app(APIShopifyService::class));
        $customer = $authService->verifyToken($accessToken, $expiresAt);
        if ($customer) {
            request()->merge(['shopify_customer_data' => $customer]);
            $log->info('Shopify customer token verification successful', [
                'token' => $accessToken,
                'customer_id' => $customer['id'] ?? null,
            ]);
            return true;
        }
        $log->warning('Shopify customer token verification failed', [
            'token' => $accessToken,
            'expires_at' => $expiresAt,
        ]);
        return false;
    }
}
