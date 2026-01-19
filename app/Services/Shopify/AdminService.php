<?php

namespace App\Services\Shopify;

use Illuminate\Support\Facades\Http;

class AdminService
{
    public function request(string $query, array $variables = [])
    {
        $endpoint = "https://" . config('shopify.store_domain') . "/admin/api/" . config('shopify.api_version') . "/graphql.json";

        $payload = ['query' => $query];

        // Only add variables if it's actually used
        if (!empty($variables)) {
            $payload['variables'] = $variables;
        }

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => config('shopify.access_token'),
            'Content-Type' => 'application/json'
        ])->post($endpoint, $payload);

        return $response->json();
    }
}
