<?php

namespace App\Services\Shopify;

use Illuminate\Support\Facades\Http;

class AdminService
{
    public function request(string $query, array $variables = [])
    {
        $endpoint = "https://" . config('shopify.store_domain') . "/admin/api/" . config('shopify.api_version') . "/graphql.json";


        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => config('shopify.access_token'),
            'Content-Type' => 'application/json'
        ])->post($endpoint, [
            'query' => $query,
            'variables' => $variables
        ]);

        return $response->json();
    }
}
