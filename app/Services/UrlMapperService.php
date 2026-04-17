<?php

namespace App\Services;

/**
 * URL Mapper Service
 * 
 * Converts Shopify web URLs to mobile API endpoints
 */
class UrlMapperService
{
    /**
     * Convert Shopify web URL to API endpoint
     * 
     * @param string $url Shopify URL
     * @param string $type Menu item type
     * @return array ['url' => string, 'api_endpoint' => string, 'params' => array]
     */
    public static function mapToApiEndpoint(string $url, string $type): array
    {
        // Parse the URL
        $parsedUrl = parse_url($url);
        $path = $parsedUrl['path'] ?? '';
        
        // Remove leading/trailing slashes
        $path = trim($path, '/');
        
        // Map based on type and path
        $mapping = self::detectEndpoint($path, $type);
        
        return [
            'url' => $url, // Original Shopify URL
            'api_endpoint' => $mapping['endpoint'],
            'params' => $mapping['params'],
            'type' => $type,
        ];
    }

    /**
     * Detect API endpoint from path and type
     * 
     * @param string $path URL path
     * @param string $type Menu item type
     * @return array
     */
    protected static function detectEndpoint(string $path, string $type): array
    {
        // Home/Frontpage
        if (empty($path) || $path === '/' || $type === 'FRONTPAGE') {
            return [
                'endpoint' => '/api/v1/home',
                'params' => [],
            ];
        }

        // Collections
        if (preg_match('#^collections/([^/]+)$#', $path, $matches)) {
            return [
                'endpoint' => '/api/v1/collections/' . $matches[1] . '/products',
                'params' => ['handle' => $matches[1]],
            ];
        }

        // Products
        if (preg_match('#^products/([^/]+)$#', $path, $matches)) {
            return [
                'endpoint' => '/api/v1/products/' . $matches[1],
                'params' => ['handle' => $matches[1]],
            ];
        }

        // Pages
        if (preg_match('#^pages/([^/]+)$#', $path, $matches)) {
            return [
                'endpoint' => '/api/v1/pages/' . $matches[1],
                'params' => ['handle' => $matches[1]],
            ];
        }

        // Blogs
        if (preg_match('#^blogs/([^/]+)$#', $path, $matches)) {
            return [
                'endpoint' => '/api/v1/blogs/' . $matches[1] . '/articles',
                'params' => ['blog_handle' => $matches[1]],
            ];
        }

        // Blog Articles
        if (preg_match('#^blogs/([^/]+)/([^/]+)$#', $path, $matches)) {
            return [
                'endpoint' => '/api/v1/blogs/' . $matches[1] . '/articles/' . $matches[2],
                'params' => [
                    'blog_handle' => $matches[1],
                    'article_handle' => $matches[2],
                ],
            ];
        }

        // Policies
        if (preg_match('#^policies/([^/]+)$#', $path, $matches)) {
            return [
                'endpoint' => '/api/v1/policies/' . $matches[1],
                'params' => ['type' => $matches[1]],
            ];
        }

        // All products/catalog
        if ($path === 'collections' || $path === 'products' || $type === 'CATALOG') {
            return [
                'endpoint' => '/api/v1/products',
                'params' => [],
            ];
        }

        // Contact
        if ($path === 'contact' || preg_match('#contact#i', $path)) {
            return [
                'endpoint' => '/api/v1/contact',
                'params' => [],
            ];
        }

        // Search
        if ($path === 'search' || preg_match('#search#i', $path)) {
            return [
                'endpoint' => '/api/v1/products/search',
                'params' => [],
            ];
        }

        // External URL or unrecognized - return as is
        return [
            'endpoint' => $path ? '/' . $path : '/',
            'params' => [],
        ];
    }

    /**
     * Get full API URL with base URL
     * 
     * @param string $endpoint API endpoint
     * @param string|null $baseUrl Base URL (optional)
     * @return string
     */
    public static function getFullApiUrl(string $endpoint, ?string $baseUrl = null): string
    {
        if (!$baseUrl) {
            $baseUrl = config('app.url', 'http://localhost');
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');
    }
}
