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
 * Supports two main workflows:
 * 1. Fetch active theme ID
 * 2. Fetch template JSON and optionally rendered HTML
 * 
 * This service retrieves template data from Shopify Online Store 2.0
 * by accessing theme assets via the Admin REST API and optionally
 * fetching rendered HTML from the storefront.
 * 
 * Cache TTL:
 * - Active Theme ID: 30 minutes (1800 seconds)
 * - Templates: 10 minutes (600 seconds)
 * 
 * Template Resolution Flow:
 * 1. Get active theme ID (cached)
 * 2. Fetch template JSON from theme assets
 * 3. Optionally fetch rendered HTML from storefront
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
     * Get active theme information
     * 
     * Retrieves the active theme ID and name with 30-minute cache TTL.
     * 
     * @return array ['theme_id' => int, 'theme_name' => string]
     * @throws ShopifyApiException
     */
    public function getActiveTheme(): array
    {
        try {
            $this->logPerformanceStart('getActiveTheme');

            $theme = $this->cacheWithFallback(
                $this->cacheStrategy->getCacheKey('active_theme', []),
                1800, // 30 minutes
                fn() => $this->fetchActiveTheme(),
                ['active_theme']
            );

            $this->logPerformanceEnd('getActiveTheme', [
                'theme_id' => $theme['theme_id'],
                'theme_name' => $theme['theme_name'],
            ]);

            return $theme;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch active theme', $e);
            throw $e;
        }
    }

    /**
     * Get theme template by handle
     * 
     * Retrieves a specific theme template by its handle with 10-minute cache TTL.
     * 
     * @param string $handle Template handle (e.g., "page", "page.about", "product.custom")
     * @param bool $includeHtml Whether to include rendered HTML (default: false)
     * @param string|null $pageHandle Page handle for HTML rendering (required if includeHtml is true)
     * @return ThemeTemplateDTO
     * @throws ShopifyNotFoundException
     * @throws ShopifyApiException
     */
    public function getTemplateByHandle(
        string $handle, 
        bool $includeHtml = false, 
        ?string $pageHandle = null
    ): ThemeTemplateDTO {
        try {
            $this->logPerformanceStart('getTemplateByHandle');

            $cacheKey = $this->cacheStrategy->getCacheKey('theme_template', [
                'handle' => $handle,
                'include_html' => $includeHtml,
                'page_handle' => $pageHandle,
            ]);

            $template = $this->cacheWithFallback(
                $cacheKey,
                600, // 10 minutes
                fn() => $this->fetchTemplateByHandle($handle, $includeHtml, $pageHandle),
                ['theme_template', $handle]
            );

            $this->logPerformanceEnd('getTemplateByHandle', [
                'handle' => $handle,
                'template_name' => $template->name,
                'include_html' => $includeHtml,
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
     * @param bool $includeHtml Whether to include rendered HTML (default: false)
     * @param string|null $pageHandle Page handle for HTML rendering (required if includeHtml is true)
     * @return ThemeTemplateDTO
     * @throws InvalidArgumentException If template type is invalid
     * @throws ShopifyNotFoundException
     * @throws ShopifyApiException
     */
    public function getTemplateByType(
        string $type, 
        ?string $suffix = null,
        bool $includeHtml = false,
        ?string $pageHandle = null
    ): ThemeTemplateDTO {
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
                'include_html' => $includeHtml,
                'page_handle' => $pageHandle,
            ]);

            $template = $this->cacheWithFallback(
                $cacheKey,
                600, // 10 minutes
                fn() => $this->fetchTemplateByHandle($handle, $includeHtml, $pageHandle),
                ['theme_template', $type]
            );

            $this->logPerformanceEnd('getTemplateByType', [
                'type' => $type,
                'suffix' => $suffix,
                'template_name' => $template->name,
                'include_html' => $includeHtml,
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
     * Get template JSON by name
     * 
     * Retrieves template JSON configuration from theme assets.
     * 
     * @param string $templateName Template name (e.g., "truro-psychic-charity")
     * @param int|null $themeId Optional theme ID (uses active theme if not provided)
     * @return array Template JSON structure
     * @throws ShopifyNotFoundException
     * @throws ShopifyApiException
     */
    public function getTemplateJson(string $templateName, ?int $themeId = null): array
    {
        try {
            $this->logPerformanceStart('getTemplateJson');

            // Get theme ID if not provided
            if ($themeId === null) {
                $activeTheme = $this->getActiveTheme();
                $themeId = $activeTheme['theme_id'];
            }

            $cacheKey = $this->cacheStrategy->getCacheKey('template_json', [
                'template_name' => $templateName,
                'theme_id' => $themeId,
            ]);

            $templateJson = $this->cacheWithFallback(
                $cacheKey,
                600, // 10 minutes
                fn() => $this->fetchTemplateJsonFromAsset($templateName, $themeId),
                ['template_json', $templateName]
            );

            $this->logPerformanceEnd('getTemplateJson', [
                'template_name' => $templateName,
                'theme_id' => $themeId,
            ]);

            return $templateJson;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch template JSON', $e, [
                'template_name' => $templateName,
                'theme_id' => $themeId,
            ]);
            throw $e;
        }
    }

    /**
     * Fetch rendered HTML from storefront
     * 
     * Retrieves the fully rendered HTML of a page from the Shopify storefront.
     * 
     * @param string $handle Page handle (e.g., "about", "contact")
     * @return string Rendered HTML
     * @throws ShopifyApiException
     */
    public function fetchRenderedHtml(string $handle): string
    {
        try {
            $this->logPerformanceStart('fetchRenderedHtml');

            $storeDomain = config('shopify.store_domain');
            $url = "https://{$storeDomain}/pages/{$handle}";

            $response = Http::timeout(config('shopify.http.timeout', 30))
                ->get($url);

            if ($response->status() === 404) {
                throw new ShopifyNotFoundException("Page not found: {$handle}");
            }

            if (!$response->successful()) {
                throw new ShopifyApiException(
                    "Failed to fetch rendered HTML for page: {$handle}. Status: {$response->status()}"
                );
            }

            $html = $response->body();

            $this->logPerformanceEnd('fetchRenderedHtml', [
                'handle' => $handle,
                'html_length' => strlen($html),
            ]);

            return $html;
        } catch (ShopifyNotFoundException | ShopifyApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ShopifyApiException(
                "Failed to fetch rendered HTML for page: {$handle}. Error: {$e->getMessage()}"
            );
        }
    }

    /**
     * Fetch active theme from Shopify Admin API
     * 
     * @return array ['theme_id' => int, 'theme_name' => string]
     * @throws ShopifyApiException
     */
    private function fetchActiveTheme(): array
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
                ->get($url);

            if (!$response->successful()) {
                throw new ShopifyApiException(
                    "Failed to fetch themes. Status: {$response->status()}"
                );
            }

            $data = $response->json();

            if (empty($data['themes'])) {
                throw new ShopifyApiException("No themes found");
            }

            // Find the active theme (role = "main")
            $activeTheme = collect($data['themes'])->firstWhere('role', 'main');

            if (!$activeTheme) {
                throw new ShopifyApiException("No active theme found");
            }

            return [
                'theme_id' => $activeTheme['id'],
                'theme_name' => $activeTheme['name'],
            ];
        } catch (ShopifyApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ShopifyApiException(
                "Failed to fetch active theme. Error: {$e->getMessage()}"
            );
        }
    }

    /**
     * Fetch template by handle from Shopify Admin API
     * 
     * @param string $handle Template handle
     * @param bool $includeHtml Whether to include rendered HTML
     * @param string|null $pageHandle Page handle for HTML rendering
     * @return ThemeTemplateDTO
     * @throws ShopifyNotFoundException
     * @throws ShopifyApiException
     */
    private function fetchTemplateByHandle(
        string $handle, 
        bool $includeHtml = false, 
        ?string $pageHandle = null
    ): ThemeTemplateDTO {
        // Step 1: Determine template file name
        $templateFileName = $this->resolveTemplateFileName($handle);

        // Step 2: Fetch theme template asset from Admin API
        $templateJson = $this->fetchThemeAsset($templateFileName);

        // Step 3: Parse template JSON
        $templateData = $this->parseTemplateJson($templateJson, $handle);

        // Step 4: Optionally fetch rendered HTML
        if ($includeHtml) {
            if (!$pageHandle) {
                throw new InvalidArgumentException(
                    "Page handle is required when includeHtml is true"
                );
            }
            $templateData['html'] = $this->fetchRenderedHtml($pageHandle);
        }

        // Step 5: Return normalized DTO
        return ThemeTemplateDTO::fromShopifyResponse($templateData);
    }

    /**
     * Fetch template JSON from theme asset
     * 
     * @param string $templateName Template name (e.g., "truro-psychic-charity")
     * @param int $themeId Theme ID
     * @return array Parsed template JSON
     * @throws ShopifyNotFoundException
     * @throws ShopifyApiException
     */
    private function fetchTemplateJsonFromAsset(string $templateName, int $themeId): array
    {
        // Build asset key
        $assetKey = "templates/page.{$templateName}.json";

        // Fetch asset
        $templateJsonString = $this->fetchThemeAssetByThemeId($assetKey, $themeId);

        // Parse JSON
        try {
            $templateJson = json_decode($templateJsonString, true, 512, JSON_THROW_ON_ERROR);
            
            return [
                'template_name' => $templateName,
                'sections' => $templateJson['sections'] ?? [],
                'blocks' => $this->extractBlocksFromSections($templateJson['sections'] ?? []),
                'order' => $templateJson['order'] ?? [],
                'settings' => $templateJson['settings'] ?? [],
            ];
        } catch (\JsonException $e) {
            throw new ShopifyApiException(
                "Failed to parse template JSON: {$e->getMessage()}"
            );
        }
    }

    /**
     * Extract blocks from sections
     * 
     * @param array $sections Sections data
     * @return array All blocks from all sections
     */
    private function extractBlocksFromSections(array $sections): array
    {
        $allBlocks = [];

        foreach ($sections as $sectionId => $sectionData) {
            if (isset($sectionData['blocks']) && is_array($sectionData['blocks'])) {
                foreach ($sectionData['blocks'] as $blockId => $blockData) {
                    $allBlocks[] = [
                        'id' => $blockId,
                        'section_id' => $sectionId,
                        'type' => $blockData['type'] ?? 'unknown',
                        'settings' => $blockData['settings'] ?? [],
                    ];
                }
            }
        }

        return $allBlocks;
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
        $activeTheme = $this->getActiveTheme();
        return $activeTheme['theme_id'];
    }

    /**
     * Fetch theme asset by theme ID
     * 
     * @param string $assetKey Asset key
     * @param int $themeId Theme ID
     * @return string Asset value
     * @throws ShopifyNotFoundException
     * @throws ShopifyApiException
     */
    private function fetchThemeAssetByThemeId(string $assetKey, int $themeId): string
    {
        try {
            $storeDomain = config('shopify.store_domain');
            $apiVersion = config('shopify.api_version', '2024-07');
            $accessToken = config('shopify.access_token');

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
                throw new ShopifyNotFoundException("Theme asset not found: {$assetKey}");
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
