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

        // Trim the request params
        $request->merge(
            array_map(function ($value) {
                return is_string($value) ? trim($value) : $value;
            }, $request->all())
        );

        return $next($request);
    }

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
