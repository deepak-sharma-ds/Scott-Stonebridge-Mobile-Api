<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class APIShopifyService
{
    protected $baseUrl;
    protected $token;
    protected $storefrontAccessToken;

    public function __construct()
    {
        $this->baseUrl = 'https://' . config('shopify.store_domain') . '/admin/api/' . config('shopify.api_version');
        $this->token = config('shopify.access_token');
        $this->storefrontAccessToken = config('shopify.storefront_access_token');
    }

    public function get($endpoint)
    {
        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->token,
            'Content-Type' => 'application/json',
        ])->get("{$this->baseUrl}/{$endpoint}.json");

        return $response->json();
    }

    public function createCustomer(array $payload)
    {
        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->token,
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/customers.json", $payload);

        return $response->json();
    }

    public function getHomePageSections()
    {
        // Get theme ID dynamically first, or hardcode active theme ID if known
        $themeId = 179834880383;

        $url = "https://" . config('shopify.store_domain') . "/admin/api/" . config('shopify.api_version') . "/themes/{$themeId}/assets.json?asset[key]=templates/index.json";

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => config('shopify.access_token'),
            'Accept' => 'application/json',
        ])->get($url);

        if ($response->failed()) {
            return ['errors' => 'Failed to fetch homepage sections'];
        }

        $assetData = $response->json();

        // The JSON content will be in assetData['asset']['value'] as a JSON string
        if (isset($assetData['asset']['value'])) {
            return json_decode($assetData['asset']['value'], true);
        }

        return ['errors' => 'Homepage JSON not found in theme asset'];
    }

    public function getMenuByHandle(string $handle)
    {
        $url = "https://" . config('shopify.store_domain') . "/api/" . config('shopify.api_version') . "/graphql.json";  // storefront endpoint
        $token = $this->storefrontAccessToken;

        $query = <<<'GRAPHQL'
        query($handle: String!) {
        menu(handle: $handle) {
            id
            title
            items {
            title
            url
            type
            items {
                title
                url
            }
            }
        }
        }
        GRAPHQL;

        $response = Http::withHeaders([
            'X-Shopify-Storefront-Access-Token' => $token,
            'Content-Type' => 'application/json',
        ])->post($url, [
            'query' => $query,
            'variables' => [
                'handle' => $handle,
            ],
        ]);

        $body = $response->json();

        if (isset($body['errors'])) {
            // handle errors
            return null;
        }

        return $body['data']['menu'] ?? null;
    }

    /**
     * Storefront/Customer API request service
     * @return array
     */
    public function storefrontApiRequest($query, $variables = [])
    {
        $url = "https://" . config('shopify.store_domain') . "/api/" . config('shopify.api_version') . "/graphql.json"; // Updated API version 2025-01

        $headers = [
            'X-Shopify-Storefront-Access-Token' => $this->storefrontAccessToken,
            'Content-Type' => 'application/json',
        ];

        $response = Http::withHeaders($headers)->post($url, [
            'query' => $query,
            'variables' => $variables,
        ]);

        return $response->json();
    }

    /**
     *  Admin/GraphQL API request service
     * @return array
     */
    public function adminApiRequest($query, $variables = [])
    {
        $url = "https://" . config('shopify.store_domain') . "/admin/api/" . config('shopify.api_version') . "/graphql.json"; // Updated API version 2025-01

        $headers = [
            'X-Shopify-Access-Token' => $this->token,
            'Content-Type' => 'application/json',
        ];

        $response = Http::withHeaders($headers)->post($url, [
            'query' => $query,
            'variables' => $variables,
        ]);

        return $response->json();
    }
}
