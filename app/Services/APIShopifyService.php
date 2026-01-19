<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class APIShopifyService
{
    protected $baseUrl;
    protected $token;
    protected $storefrontAccessToken;
    protected $currency;

    public function __construct()
    {
        $this->baseUrl = 'https://' . config('shopify.store_domain') . '/admin/api/' . config('shopify.api_version');
        $this->token = config('shopify.access_token');
        $this->storefrontAccessToken = config('shopify.storefront_access_token');
        $this->currency = config('app.currency', 'GBP');
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
     */
    public function storefrontApiRequest($query, $variables = [])
    {
        $url = "https://" . config('shopify.store_domain') . "/api/" . config('shopify.api_version') . "/graphql.json"; // Updated API version 2025-01

        $headers = [
            'X-Shopify-Storefront-Access-Token' => $this->storefrontAccessToken,
            'Content-Type' => 'application/json',
        ];

        // if (!isset($variables['currencyCode'])) {
        //     $variables['currencyCode'] = $this->currency;
        // }

        $response = Http::withHeaders($headers)->post($url, [
            'query' => $query,
            'variables' => $variables,
        ]);

        return $response->json();
    }

    /**
     *  Admin/GraphQL API request service
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





    public function getOrdersCountBetween($from, $to)
    {
        $totalCount = 0;
        $limit = 250;
        $endpoint = "{$this->baseUrl}/orders.json";

        // FIRST REQUEST — filters allowed
        $params = [
            'limit' => $limit,
            'status' => 'any',
            'created_at_min' => $from->toIso8601String(),
            'created_at_max' => $to->toIso8601String(),
            'fields' => 'id',
        ];

        // -------- FIRST PAGE REQUEST --------
        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->token,
            'Content-Type' => 'application/json',
        ])->get($endpoint, $params);

        if ($response->failed()) {
            throw new \Exception('Shopify getOrdersCountBetween failed: ' . $response->body());
        }

        $orders = $response->json('orders') ?? [];
        $totalCount += count($orders);

        $nextPageInfo = $this->extractNextPageInfo($response->header('Link'));

        // -------- NEXT PAGES: ONLY page_info + limit allowed --------
        while ($nextPageInfo) {

            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $this->token,
                'Content-Type' => 'application/json',
            ])->get($endpoint, [
                'limit' => $limit,
                'page_info' => $nextPageInfo,
            ]);

            if ($response->failed()) {
                throw new \Exception('Shopify getOrdersCountBetween failed (pagination): ' . $response->body());
            }

            $orders = $response->json('orders') ?? [];
            $totalCount += count($orders);

            $nextPageInfo = $this->extractNextPageInfo($response->header('Link'));
        }

        return $totalCount;
    }

    public function getSalesTotalBetween($from, $to)
    {
        $total = 0.0;
        $limit = 250;
        $endpoint = "{$this->baseUrl}/orders.json";

        $params = [
            'limit' => $limit,
            'status' => 'any',
            'created_at_min' => $from->toIso8601String(),
            'created_at_max' => $to->toIso8601String(),
            'fields' => 'id,total_price',
        ];

        $nextPageInfo = null;

        do {
            $query = $params;
            if ($nextPageInfo) {
                $query['page_info'] = $nextPageInfo;
            }

            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $this->token,
                'Content-Type' => 'application/json',
            ])->get($endpoint, $query);

            if ($response->failed()) {
                throw new \Exception('Shopify getSalesTotalBetween failed: ' . $response->body());
            }

            $orders = $response->json('orders') ?? [];

            foreach ($orders as $order) {
                $total += isset($order['total_price']) ? (float)$order['total_price'] : 0;
            }

            // Parse pagination
            $nextPageInfo = $this->extractNextPageInfo($response->header('Link'));
        } while ($nextPageInfo);

        return round($total, 2);
    }


    public function getTopProducts($limit = 10)
    {
        $query = <<<'GRAPHQL'
            query($limit: Int!) {
                products(first: $limit, sortKey: BEST_SELLING) {
                    edges {
                        node {
                            id
                            title
                            handle
                            featuredImage { originalSrc }
                            totalInventory
                        }
                    }
                }
            }
        GRAPHQL;

        $response = $this->adminApiRequest($query, ['limit' => $limit]);

        if (!isset($response['data']['products'])) {
            throw new \Exception("Shopify getTopProducts error: " . json_encode($response));
        }

        return $response['data']['products']['edges'] ?? [];
    }

    protected function extractNextPageInfo($linkHeader)
    {
        if (!$linkHeader) return null;

        // Example Link:
        // <https://xxx.myshopify.com/admin/api/2024-10/orders.json?page_info=abc&limit=250>; rel="next"

        foreach (explode(',', $linkHeader) as $part) {
            if (strpos($part, 'rel="next"') !== false) {
                if (preg_match('/<([^>]+)>/', $part, $matches)) {
                    $url = $matches[1];
                    $query = parse_url($url, PHP_URL_QUERY);
                    parse_str($query, $params);

                    return $params['page_info'] ?? null;
                }
            }
        }

        return null;
    }
}
