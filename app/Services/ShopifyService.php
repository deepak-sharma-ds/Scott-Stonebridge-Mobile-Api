<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ShopifyService
{
    protected $accessToken;
    protected $storeDomain;
    protected $apiVersion;

    public function __construct()
    {
        $this->accessToken = config('shopify.access_token');
        $this->storeDomain = config('shopify.store_domain');
        $this->apiVersion = config('shopify.api_version');
    }

    public function sendHtmlToShopifyPage($title, $htmlContent)
    {
        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->accessToken,
            'Content-Type' => 'application/json'
        ])->post("https://{$this->storeDomain}/admin/api/{$this->apiVersion}/pages.json", [
            'page' => [
                'title' => $title,
                'body_html' => $htmlContent,
            ]
        ]);

        return $response->json();
    }
}
