<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Shopify\ShopifyAdapterInterface;
use App\Services\Shopify\GraphQLLoaderService;
use Illuminate\Support\Facades\Log;

class ContentService
{
    // Theme ID now in config/shopify.php
    // private const THEME_ID = '179834880383';

    public function __construct(
        private readonly ShopifyAdapterInterface $adapter,
        private readonly GraphQLLoaderService $queryLoader
    ) {}

    /**
     * Get Menu by Handle (Storefront API)
     */
    public function getMenu(string $handle): array
    {
        $query = $this->queryLoader->load('storefront/content/get_menu');
        $variables = ['handle' => $handle];

        try {
            $response = $this->adapter->storefrontQuery($query, $variables);
            return data_get($response, 'menu', []);
        } catch (\Throwable $e) {
            Log::error('Failed to fetch menu', ['handle' => $handle, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get Homepage Sections (Admin REST API -> Theme Asset)
     */
    public function getHomePageSections(): array
    {
        $themeId = config('shopify.theme_id');
        $asset = $this->adapter->getThemeAsset($themeId, 'templates/index.json');
        
        if (!$asset || empty($asset['value'])) {
             Log::warning('Homepage sections not found');
             return [];
        }

        return json_decode($asset['value'], true);
    }

    /**
     * Get Header Group Sections (Admin REST API -> Theme Asset)
     */
    public function getHeaderGroup(): array
    {
        $themeId = config('shopify.theme_id');
        $asset = $this->adapter->getThemeAsset($themeId, 'sections/header-group.json');
        
        if (!$asset || empty($asset['value'])) {
             Log::warning('Header group sections not found');
             return [];
        }
        
        return json_decode($asset['value'], true);
    }
}
