<?php

namespace App\Http\Requests\ShopifyApp;

use App\Http\Requests\Bundles\BundleRequest;

/**
 * Same rules as the Laravel admin form. Access is already enforced by the
 * Shopify session-token middleware (anyone with access to the app in the Shopify admin).
 */
class BundleApiRequest extends BundleRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
