<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyShopifyHmac
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // $hmacHeader = $request->header('X-Shopify-Hmac-Sha256');
        // if (!$hmacHeader) {
        //     return response()->json(['error' => 'Missing HMAC header'], 403);
        // }

        // $calculated = base64_encode(hash_hmac(
        //     'sha256',
        //     $request->getContent(),
        //     config('shopify.api_secret'),
        //     true
        // ));

        // if (!hash_equals($hmacHeader, $calculated)) {
        //     return response()->json(['error' => 'Invalid HMAC signature'], 403);
        // }

        // return $next($request);

        if ($request->header('X-App-Secret') !== config('shopify.api_secret')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
    }
}
