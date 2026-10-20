<?php

namespace App\Http\Middleware;

use App\Services\Bundles\ShopifyBundleCredentials;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows the embedded app page to be framed by the Shopify admin (and nothing else).
 */
class ShopifyEmbeddedHeaders
{
    public function __construct(protected ShopifyBundleCredentials $credentials)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $shop = $this->credentials->shopDomain();
        $ancestors = trim(($shop ? "https://{$shop} " : '') . 'https://admin.shopify.com');

        $response->headers->set('Content-Security-Policy', "frame-ancestors {$ancestors};");
        $response->headers->remove('X-Frame-Options');

        return $response;
    }
}
