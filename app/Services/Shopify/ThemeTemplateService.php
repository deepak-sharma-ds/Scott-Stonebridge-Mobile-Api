<?php

namespace App\Services\Shopify;

use App\Contracts\Services\ThemeServiceInterface;
use App\Contracts\Shopify\AdminApiClientInterface;
use App\DTOs\Theme\ThemeTemplateDTO;
use App\Exceptions\ShopifyApiException;
use App\Exceptions\ShopifyNotFoundException;
use App\Services\Base\BaseService;
use App\Services\Cache\ShopifyCacheStrategy;
use App\Traits\CacheWithFallback;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Theme Template Service
 * 
 * Handles Shopify theme template operations using the Admin API.
 * Fetches theme template JSON files from theme assets.
 * 
 * This service correctly retrieves template data from Shopify Online Store 2.0
 * by accessing theme assets via the Admin REST API.
 * 
 * Cache TTL:
 * - Templates: 1 hour (3600 seconds)
 * 
 * Template Resolution Flow:
 * 1. Determine template name from page metadata (templates/page.{suffix}.json or templates/page.json)
 * 2. Fetch theme template asset from Admin API
 * 3. Parse template JSON structure
 * 4. Return normalized template data
 */
class ThemeTemplateService extends BaseService implements ThemeServiceInterface
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
     * @param AdminApiClientInterface $adminClient Admin API client for REST requests
     * @param ShopifyCacheStrategy $cacheStrategy Cache strategy for key generation
     */
    public function __construct(
        private readonly AdminApiClientInterface $adminClient,
        private readonly ShopifyCacheStrategy $cacheStrategy
    ) {
        parent::__construct();
    }

    /**
     * Get theme template by handle
     * 
     * Retrieves a specific theme template by its handle with 1-hour cache TTL.
     * 
     * @param string $handle Template handle (e.g., "page", "page.about", "product.custom")
     * @return ThemeTemplateDTO
     * @throws ShopifyNotFoundException
     * @throws ShopifyApiException
     */
    public function getTemplateByHandle(string $handle): ThemeTemplateDTO
    {
        try {
            $this->logPerformanceStart('getTemplateByHandle');

            $template = $this->cacheWithFallback(
                $this->cacheStrategy->getCacheKey('theme_template', ['handle' => $handle]),
                3600, // 1 hour
                fn() => $this->fetchTemplateByHandle($handle),
                ['theme_template', $handle]
            );

            $this->logPerformanceEnd('getTemplateByHandle', [
                'handle' => $handle,
                'template_name' => $template->name,
            ]);

            return $template;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch theme template', $e, ['handle' => $handle]);
            throw $e;
        }
    }

    /**
     * Get template by type and optional suffix
     * 
     * Retrieves a theme template by type and optional suffix.
     * 
     * @param string $type Template type (product, collection, page, article, etc.)
     * @param string|null $suffix Optional template suffix for alternate templates
     * @return ThemeTemplateDTO
     * @throws InvalidArgumentException If template type is invalid
     * @throws ShopifyNotFoundException
     * @throws ShopifyApiException
     */
    public function getTemplateByType(string $type, ?string $suffix = null): ThemeTemplateDTO
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

            // Build template handle
            $handle = $suffix ? "{$type}.{$suffix}" : $type;

            $cacheKey = $this->cacheStrategy->getCacheKey('theme_template_by_type', [
                'type' => $type,
                'suffix' => $suffix,
            ]);

            $template = $this->cacheWithFallback(
                $cacheKey,
                3600, // 1 hour
                fn() => $this->fetchTemplateByHandle($handle),
                ['theme_template', $type]
            );

            $this->logPerformanceEnd('getTemplateByType', [
                'type' => $type,
                'suffix' => $suffix,
                'template_name' => $template->name,
            ]);

            return $template;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch theme template by type', $e, [
                'type' => $type,
                'suffix' => $suffix,
            ]);
            throw $e;
        }
    }

    /**
     * Fetch template by handle from Shopify Admin API
     * 
     * @param string $handle Template handle
     * @return ThemeTemplateDTO
     * @throws ShopifyNotFoundException
     * @throws ShopifyApiException
     */
    private function fetchTemplateByHandle(string $handle): ThemeTemplateDTO
    {
        // Step 1: Determine template file name
        $templateFileName = $this->resolveTemplateFileName($handle);

        // Step 2: Fetch theme template asset from Admin API
        $templateJson = $this->fetchThemeAsset($templateFileName);

        // Step 3: Parse template JSON
        $templateData = $this->parseTemplateJson($templateJson, $handle);

        // Step 4: Return normalized DTO
        return ThemeTemplateDTO::fromShopifyResponse($templateData);
    }

    /**
     * Resolve template file name from handle
     * 
     * Converts template handle to template file path.
     * Examples:
     * - "page" -> "templates/page.json"
     * - "page.about" -> "templates/page.about.json"
     * - "product.custom" -> "templates/product.custom.json"
     * 
     * @param string $handle Template handle
     * @return string Template file path
     */
    private function resolveTemplateFileName(string $handle): string
    {
        return "templates/{$handle}.json";
    }

    /**
     * Fetch theme asset from Shopify Admin REST API
     * 
     * Uses the Admin REST API to fetch theme template assets.
     * Endpoint: GET /admin/api/{version}/themes/{theme_id}/assets.json?asset[key]={template_file}
     * 
     * @param string $assetKey Asset key (e.g., "templates/page.about.json")
     * @return string Template JSON content
     * @throws ShopifyNotFoundException
     * @throws ShopifyApiException
     */
    private function fetchThemeAsset(string $assetKey): string
    {
        try {
            $storeDomain = config('shopify.store_domain');
            $apiVersion = config('shopify.api_version', '2024-07');
            $accessToken = config('shopify.access_token');
            $themeId = $this->getActiveThemeId();

            $url = "https://{$storeDomain}/admin/api/{$apiVersion}/themes/{$themeId}/assets.json";

            $response = Http::timeout(config('shopify.http.timeout', 30))
                ->withHeaders([
                    'X-Shopify-Access-Token' => $accessToken,
                    'Content-Type' => 'application/json',
                ])
                ->get($url, [
                    'asset[key]' => $assetKey,
                ]);

            if ($response->status() === 404) {
                throw new ShopifyNotFoundException("Theme template not found: {$assetKey}");
            }

            if (!$response->successful()) {
                throw new ShopifyApiException(
                    "Failed to fetch theme asset: {$assetKey}. Status: {$response->status()}"
                );
            }

            $data = $response->json();

            if (!isset($data['asset']['value'])) {
                throw new ShopifyApiException("Invalid theme asset response: missing 'value' field");
            }

            return $data['asset']['value'];
        } catch (ShopifyNotFoundException | ShopifyApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ShopifyApiException(
                "Failed to fetch theme asset: {$assetKey}. Error: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get active theme ID
     * 
     * Retrieves the active theme ID from configuration or fetches it from Shopify.
     * 
     * @return int Theme ID
     * @throws ShopifyApiException
     */
    private function getActiveThemeId(): int
    {
        // Check if theme ID is configured
        $themeId = config('shopify.theme_id');

        if ($themeId) {
            return (int) $themeId;
        }

        // Fetch active theme from Shopify
        return $this->fetchActiveThemeId();
    }

    /**
     * Fetch active theme ID from Shopify Admin REST API
     * 
     * @return int Theme ID
     * @throws ShopifyApiException
     */
    private function fetchActiveThemeId(): int
    {
        try {
            $storeDomain = config('shopify.store_domain');
            $apiVersion = config('shopify.api_version', '2024-07');
            $accessToken = config('shopify.access_token');

            $url = "https://{$storeDomain}/admin/api/{$apiVersion}/themes.json";

            $response = Http::timeout(config('shopify.http.timeout', 30))
                ->withHeaders([
                    'X-Shopify-Access-Token' => $accessToken,
                    'Content-Type' => 'application/json',
                ])
                ->get($url, [
                    'role' => 'main',
                ]);

            if (!$response->successful()) {
                throw new ShopifyApiException(
                    "Failed to fetch active theme. Status: {$response->status()}"
                );
            }

            $data = $response->json();

            if (empty($data['themes'])) {
                throw new ShopifyApiException("No active theme found");
            }

            return $data['themes'][0]['id'];
        } catch (ShopifyApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ShopifyApiException(
                "Failed to fetch active theme ID. Error: {$e->getMessage()}"
            );
        }
    }

    /**
     * Parse template JSON structure
     * 
     * Parses the template JSON and extracts sections, blocks, and settings.
     * 
     * Template structure example:
     * {
     *   "sections": {
     *     "main": {
     *       "type": "main-page",
     *       "settings": {...}
     *     },
     *     "rich_text": {
     *       "type": "rich-text",
     *       "blocks": {...}
     *     }
     *   },
     *   "order": [...]
     * }
     * 
     * @param string $templateJson Template JSON content
     * @param string $handle Template handle
     * @return array Normalized template data
     * @throws ShopifyApiException
     */
    private function parseTemplateJson(string $templateJson, string $handle): array
    {
        try {
            $template = json_decode($templateJson, true, 512, JSON_THROW_ON_ERROR);

            // Extract template type from handle
            $parts = explode('.', $handle);
            $type = $parts[0];
            $suffix = isset($parts[1]) ? $parts[1] : null;

            // Parse sections
            $sections = [];
            if (isset($template['sections']) && is_array($template['sections'])) {
                foreach ($template['sections'] as $sectionId => $sectionData) {
                    $sections[] = [
                        'id' => $sectionId,
                        'type' => $sectionData['type'] ?? 'unknown',
                        'settings' => $sectionData['settings'] ?? [],
                        'blocks' => $this->parseBlocks($sectionData['blocks'] ?? []),
                    ];
                }
            }

            // Build normalized response
            return [
                'id' => "template/{$handle}",
                'handle' => $handle,
                'type' => $type,
                'name' => $this->generateTemplateName($handle),
                'suffix' => $suffix,
                'sections' => $sections,
                'settings' => $template['settings'] ?? [],
                'order' => $template['order'] ?? [],
                'metadata' => [
                    'wrapper' => $template['wrapper'] ?? null,
                    'layout' => $template['layout'] ?? null,
                ],
                'createdAt' => now()->toIso8601String(),
                'updatedAt' => now()->toIso8601String(),
            ];
        } catch (\JsonException $e) {
            throw new ShopifyApiException(
                "Failed to parse template JSON: {$e->getMessage()}"
            );
        }
    }

    /**
     * Parse blocks from section data
     * 
     * @param array $blocksData Blocks data from section
     * @return array Parsed blocks
     */
    private function parseBlocks(array $blocksData): array
    {
        $blocks = [];

        foreach ($blocksData as $blockId => $blockData) {
            $blocks[] = [
                'id' => $blockId,
                'type' => $blockData['type'] ?? 'unknown',
                'settings' => $blockData['settings'] ?? [],
            ];
        }

        return $blocks;
    }

    /**
     * Generate human-readable template name from handle
     * 
     * @param string $handle Template handle
     * @return string Template name
     */
    private function generateTemplateName(string $handle): string
    {
        $parts = explode('.', $handle);
        $type = ucfirst($parts[0]);
        $suffix = isset($parts[1]) ? ' - ' . ucwords(str_replace(['-', '_'], ' ', $parts[1])) : '';

        return "{$type} Template{$suffix}";
    }
}
