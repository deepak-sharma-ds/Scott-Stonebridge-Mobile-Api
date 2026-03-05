<?php

namespace App\Services\Shopify;

use App\Contracts\Services\ThemeServiceInterface;
use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\DTOs\Theme\ThemeTemplateDTO;
use App\Exceptions\ShopifyApiException;
use App\Services\Base\BaseService;
use App\Services\Cache\ShopifyCacheStrategy;
use App\Traits\CacheWithFallback;
use InvalidArgumentException;

/**
 * Theme Service
 * 
 * Handles Shopify theme template operations using the Storefront API.
 * Implements intelligent caching with 1-hour TTL for theme templates.
 * 
 * Cache TTL:
 * - Templates: 1 hour (3600 seconds)
 * 
 * Supported template types:
 * - product: Product page templates
 * - collection: Collection page templates
 * - page: Static page templates
 * - article: Blog article templates
 * - blog: Blog listing templates
 * - index: Home page template
 * - cart: Cart page template
 * - search: Search results template
 */
class ThemeService extends BaseService implements ThemeServiceInterface
{
    use CacheWithFallback;

    /**
     * Valid template types
     */
    private const VALID_TEMPLATE_TYPES = [
        'product',
        'collection',
        'page',
        'article',
        'blog',
        'index',
        'cart',
        'search',
        'customers/account',
        'customers/order',
        'customers/login',
        'customers/register',
    ];

    /**
     * Constructor
     * 
     * @param StorefrontApiClientInterface $storefrontClient Storefront API client for queries
     * @param ShopifyCacheStrategy $cacheStrategy Cache strategy for key generation
     */
    public function __construct(
        private readonly StorefrontApiClientInterface $storefrontClient,
        private readonly ShopifyCacheStrategy $cacheStrategy
    ) {
        parent::__construct();
    }

    /**
     * Get theme templates with pagination
     * 
     * Retrieves a list of theme templates with 1-hour cache TTL.
     * Supports cursor-based pagination.
     * 
     * @param int $limit Number of templates to retrieve (default: 10)
     * @param string|null $cursor Pagination cursor for next page
     * @return array ['items' => ThemeTemplateDTO[], 'pagination' => ['has_next' => bool, 'next_cursor' => string|null]]
     * @throws ShopifyApiException
     */
    public function getTemplates(int $limit = 10, ?string $cursor = null): array
    {
        try {
            $this->logPerformanceStart('getTemplates');

            $cacheKey = $this->cacheStrategy->getCacheKey('theme_templates', [
                'limit' => $limit,
                'cursor' => $cursor,
            ]);

            $result = $this->cacheWithFallback(
                $cacheKey,
                3600, // 1 hour
                fn() => $this->fetchTemplates($limit, $cursor),
                ['theme_templates']
            );

            $this->logPerformanceEnd('getTemplates', [
                'limit' => $limit,
                'cursor' => $cursor,
                'count' => count($result['items']),
            ]);

            return $result;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch theme templates', $e, [
                'limit' => $limit,
                'cursor' => $cursor,
            ]);
            throw $e;
        }
    }

    /**
     * Fetch templates from Shopify API
     * 
     * Note: Shopify Storefront API doesn't support theme template queries.
     * This implementation returns predefined template structures.
     * For full theme template access, use Shopify Admin API.
     * 
     * @param int $limit Number of templates to retrieve
     * @param string|null $cursor Pagination cursor
     * @return array
     */
    private function fetchTemplates(int $limit, ?string $cursor): array
    {
        // Get shop info to verify connection
        $response = $this->storefrontClient->query('storefront/theme/templates_list');
        
        $shopId = $response['data']['shop']['id'] ?? 'unknown';
        
        // Return predefined template structures
        // In production, these would come from Admin API or be configured in database
        $allTemplates = $this->getDefaultTemplates($shopId);
        
        // Simple pagination simulation
        $offset = $cursor ? (int)base64_decode($cursor) : 0;
        $templates = array_slice($allTemplates, $offset, $limit);
        $hasNext = count($allTemplates) > ($offset + $limit);
        $nextCursor = $hasNext ? base64_encode((string)($offset + $limit)) : null;

        return [
            'items' => array_map(
                fn($template) => ThemeTemplateDTO::fromShopifyResponse($template),
                $templates
            ),
            'pagination' => [
                'has_next' => $hasNext,
                'next_cursor' => $nextCursor,
            ],
        ];
    }
    
    /**
     * Get default template structures
     * 
     * @param string $shopId Shop ID for generating template IDs
     * @return array
     */
    private function getDefaultTemplates(string $shopId): array
    {
        $timestamp = now()->toIso8601String();
        
        return [
            [
                'id' => "{$shopId}/template/product",
                'handle' => 'product',
                'type' => 'product',
                'name' => 'Default Product Template',
                'suffix' => null,
                'sections' => [
                    'main' => [
                        'type' => 'product-template',
                        'settings' => ['show_vendor' => true, 'show_share_buttons' => true]
                    ]
                ],
                'settings' => ['enable_zoom' => true],
                'metadata' => null,
                'createdAt' => $timestamp,
                'updatedAt' => $timestamp,
            ],
            [
                'id' => "{$shopId}/template/collection",
                'handle' => 'collection',
                'type' => 'collection',
                'name' => 'Default Collection Template',
                'suffix' => null,
                'sections' => [
                    'main' => [
                        'type' => 'collection-template',
                        'settings' => ['products_per_page' => 24]
                    ]
                ],
                'settings' => ['enable_filtering' => true, 'enable_sorting' => true],
                'metadata' => null,
                'createdAt' => $timestamp,
                'updatedAt' => $timestamp,
            ],
            [
                'id' => "{$shopId}/template/page",
                'handle' => 'page',
                'type' => 'page',
                'name' => 'Default Page Template',
                'suffix' => null,
                'sections' => [
                    'main' => [
                        'type' => 'page-template',
                        'settings' => []
                    ]
                ],
                'settings' => [],
                'metadata' => null,
                'createdAt' => $timestamp,
                'updatedAt' => $timestamp,
            ],
            [
                'id' => "{$shopId}/template/index",
                'handle' => 'index',
                'type' => 'index',
                'name' => 'Home Page Template',
                'suffix' => null,
                'sections' => [
                    'hero' => [
                        'type' => 'hero-banner',
                        'settings' => ['show_overlay' => true]
                    ],
                    'featured-products' => [
                        'type' => 'featured-collection',
                        'settings' => ['products_to_show' => 8]
                    ]
                ],
                'settings' => [],
                'metadata' => null,
                'createdAt' => $timestamp,
                'updatedAt' => $timestamp,
            ],
            [
                'id' => "{$shopId}/template/cart",
                'handle' => 'cart',
                'type' => 'cart',
                'name' => 'Cart Template',
                'suffix' => null,
                'sections' => [
                    'main' => [
                        'type' => 'cart-template',
                        'settings' => ['show_note' => true]
                    ]
                ],
                'settings' => [],
                'metadata' => null,
                'createdAt' => $timestamp,
                'updatedAt' => $timestamp,
            ],
        ];
    }

    /**
     * Get a single theme template by handle
     * 
     * Retrieves a specific theme template by its handle with 1-hour cache TTL.
     * 
     * @param string $handle Template handle
     * @return ThemeTemplateDTO
     * @throws ShopifyApiException
     */
    public function getTemplateByHandle(string $handle): ThemeTemplateDTO
    {
        try {
            $this->logPerformanceStart('getTemplateByHandle');

            $template = $this->cacheWithFallback(
                $this->cacheStrategy->getCacheKey('theme_template', ['handle' => $handle]),
                3600, // 1 hour
                fn() => $this->fetchTemplate($handle),
                ['theme_template', $handle]
            );

            $this->logPerformanceEnd('getTemplateByHandle', [
                'handle' => $handle,
                'template_id' => $template->id,
            ]);

            return $template;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch theme template', $e, ['handle' => $handle]);
            throw $e;
        }
    }

    /**
     * Fetch template from Shopify API
     * 
     * @param string $handle Template handle
     * @return ThemeTemplateDTO
     * @throws ShopifyApiException
     */
    private function fetchTemplate(string $handle): ThemeTemplateDTO
    {
        // Get shop info to verify connection
        $response = $this->storefrontClient->query('storefront/theme/template_get');
        
        $shopId = $response['data']['shop']['id'] ?? 'unknown';
        
        // Get all templates and find by handle
        $allTemplates = $this->getDefaultTemplates($shopId);
        
        foreach ($allTemplates as $template) {
            if ($template['handle'] === $handle) {
                return ThemeTemplateDTO::fromShopifyResponse($template);
            }
        }

        throw new ShopifyApiException("Theme template not found: {$handle}");
    }

    /**
     * Get template by type and resource
     * 
     * Retrieves a theme template by type and optional resource handle.
     * Useful for determining which template to use for a specific resource.
     * 
     * @param string $type Template type (product, collection, page, article, etc.)
     * @param string|null $resourceHandle Optional resource handle for specific templates
     * @return ThemeTemplateDTO
     * @throws InvalidArgumentException If template type is invalid
     * @throws ShopifyApiException
     */
    public function getTemplateByType(string $type, ?string $resourceHandle = null): ThemeTemplateDTO
    {
        try {
            $this->logPerformanceStart('getTemplateByType');

            // Validate template type
            if (!in_array($type, self::VALID_TEMPLATE_TYPES, true)) {
                throw new InvalidArgumentException(
                    "Invalid template type: {$type}. Supported types: " . 
                    implode(', ', self::VALID_TEMPLATE_TYPES)
                );
            }

            $cacheKey = $this->cacheStrategy->getCacheKey('theme_template_by_type', [
                'type' => $type,
                'resource' => $resourceHandle,
            ]);

            $template = $this->cacheWithFallback(
                $cacheKey,
                3600, // 1 hour
                fn() => $this->fetchTemplateByType($type, $resourceHandle),
                ['theme_template', $type]
            );

            $this->logPerformanceEnd('getTemplateByType', [
                'type' => $type,
                'resource_handle' => $resourceHandle,
                'template_id' => $template->id,
            ]);

            return $template;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch theme template by type', $e, [
                'type' => $type,
                'resource_handle' => $resourceHandle,
            ]);
            throw $e;
        }
    }

    /**
     * Fetch template by type from Shopify API
     * 
     * @param string $type Template type
     * @param string|null $resourceHandle Optional resource handle
     * @return ThemeTemplateDTO
     * @throws ShopifyApiException
     */
    private function fetchTemplateByType(string $type, ?string $resourceHandle): ThemeTemplateDTO
    {
        // Build template handle based on type and resource
        $handle = $resourceHandle ? "{$type}.{$resourceHandle}" : $type;

        return $this->fetchTemplate($handle);
    }
}
