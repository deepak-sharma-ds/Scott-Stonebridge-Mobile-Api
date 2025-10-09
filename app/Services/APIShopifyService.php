<?php

namespace App\Services;
use Illuminate\Support\Facades\Http;

class APIShopifyService
{
    protected $baseUrl;
    protected $token;

    public function __construct()
    {
        $this->baseUrl = 'https://' . config('shopify.store_domain') . '/admin/api/2024-07';
        $this->token = config('shopify.access_token');
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

    /**
     * Create a customer access token via Shopify Storefront API (GraphQL).
     *
     * @param string $email
     * @param string $password
     * @return array|null Returns access token info or error details
     */
    public function customerAccessTokenCreate(string $email, string $password): ?array
    {
        $storefrontUrl = 'https://' . config('shopify.store_domain') . '/api/2024-07/graphql.json';

        $query = <<<'GRAPHQL'
        mutation customerAccessTokenCreate($input: CustomerAccessTokenCreateInput!) {
            customerAccessTokenCreate(input: $input) {
                customerAccessToken {
                    accessToken
                    expiresAt
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;

        $variables = [
            'input' => [
                'email' => $email,
                'password' => $password,
            ],
        ];

        $response = Http::withHeaders([
            'X-Shopify-Storefront-Access-Token' => config('shopify.storefront_access_token'),
            'Content-Type' => 'application/json',
        ])->post($storefrontUrl, [
            'query' => $query,
            'variables' => $variables,
        ]);

        $json = $response->json();

        // Check for errors
        if (isset($json['errors'])) {
            // GraphQL-level errors
            return [
                'success' => false,
                'errors' => $json['errors'],
            ];
        }

        $result = $json['data']['customerAccessTokenCreate'] ?? null;

        if (!$result) {
            return [
                'success' => false,
                'errors' => ['Unknown error occurred'],
            ];
        }

        if (!empty($result['userErrors'])) {
            return [
                'success' => false,
                'errors' => $result['userErrors'],
            ];
        }

        // Successful login, return token data
        return [
            'success' => true,
            'token' => $result['customerAccessToken']['accessToken'],
            'expiresAt' => $result['customerAccessToken']['expiresAt'],
        ];
    }

    public function sendPasswordResetEmail($email)
    {
        $storefrontUrl = 'https://' . config('shopify.store_domain') . '/api/2024-07/graphql.json';

        $query = <<<'GRAPHQL'
            mutation customerRecover($email: String!) {
                customerRecover(email: $email) {
                    customerUserErrors {
                        code
                        field
                        message
                    }
                }
            }
        GRAPHQL;

        $response = Http::withHeaders([
            'X-Shopify-Storefront-Access-Token' => config('shopify.storefront_access_token'),
            'Content-Type' => 'application/json',
        ])->post($storefrontUrl, [
            'query' => $query,
            'variables' => [
                'email' => $email,
            ],
        ]);

        return $response->json();
    }

    public function getHomePageSections() 
    {
        // Get theme ID dynamically first, or hardcode active theme ID if known
        $themeId = 179834880383;

        $url = "https://" . config('shopify.store_domain') . "/admin/api/2025-01/themes/{$themeId}/assets.json?asset[key]=templates/index.json";

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
        $url = "https://" . config('shopify.store_domain') . "/api/2024-07/graphql.json";  // storefront endpoint
        $token = config('shopify.storefront_access_token');

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

    /* Storefront API request service */
    public function storefrontApiRequest($query, $variables = [])
    {
        $url = "https://" . config('shopify.store_domain') . "/api/2024-07/graphql.json"; // Updated API version 2025-01
        $storefrontAccessToken = config('shopify.storefront_access_token');

        $headers = [
            'X-Shopify-Storefront-Access-Token' => $storefrontAccessToken,
            'Content-Type' => 'application/json',
        ];

        // if ($customerAccessToken) {
        //     $headers['Authorization'] = "Bearer {$customerAccessToken}";
        // }

        $response = Http::withHeaders($headers)->post($url, [
            'query' => $query,
            'variables' => $variables,
        ]);

        return $response->json();
    }
}
