<?php

namespace App\Services\Shopify;

use Illuminate\Support\Facades\Http;

class StorefrontService
{
    public function request(string $query, array $variables = [])
    {
        $endpoint = "https://" . config('shopify.store_domain') . "/api/" . config('shopify.api_version') . "/graphql.json";

        $response = Http::withHeaders([
            'X-Shopify-Storefront-Access-Token' => config('shopify.storefront_access_token'),
            'Content-Type' => 'application/json'
        ])->post($endpoint, [
            'query' => $query,
            'variables' => $variables
        ]);

        return $response->json();
    }
}
