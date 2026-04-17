<?php

namespace App\Contracts\Services;

use App\DTOs\Theme\ThemeTemplateDTO;

/**
 * Theme Service Interface
 * 
 * Defines the contract for Shopify theme template operations.
 * Provides access to theme templates and rendered HTML for dynamic content.
 * 
 * Uses Shopify Admin API to fetch theme template assets from Online Store 2.0
 * and optionally fetches rendered HTML from the storefront.
 */
interface ThemeServiceInterface
{
    /**
     * Get active theme information
     * 
     * @return array ['theme_id' => int, 'theme_name' => string]
     */
    public function getActiveTheme(): array;

    /**
     * Get a single theme template by handle
     * 
     * @param string $handle Template handle (e.g., "page", "page.about", "product.custom")
     * @param bool $includeHtml Whether to include rendered HTML (default: false)
     * @param string|null $pageHandle Page handle for HTML rendering (required if includeHtml is true)
     * @return ThemeTemplateDTO
     */
    public function getTemplateByHandle(
        string $handle, 
        bool $includeHtml = false, 
        ?string $pageHandle = null
    ): ThemeTemplateDTO;

    /**
     * Get template by type and optional suffix
     * 
     * @param string $type Template type (product, collection, page, article, etc.)
     * @param string|null $suffix Optional template suffix for alternate templates
     * @param bool $includeHtml Whether to include rendered HTML (default: false)
     * @param string|null $pageHandle Page handle for HTML rendering (required if includeHtml is true)
     * @return ThemeTemplateDTO
     */
    public function getTemplateByType(
        string $type, 
        ?string $suffix = null,
        bool $includeHtml = false,
        ?string $pageHandle = null
    ): ThemeTemplateDTO;

    /**
     * Get template JSON by name
     * 
     * @param string $templateName Template name (e.g., "truro-psychic-charity")
     * @param int|null $themeId Optional theme ID (uses active theme if not provided)
     * @return array Template JSON structure
     */
    public function getTemplateJson(string $templateName, ?int $themeId = null): array;

    /**
     * Fetch rendered HTML from storefront
     * 
     * @param string $handle Page handle (e.g., "about", "contact")
     * @return string Rendered HTML
     */
    public function fetchRenderedHtml(string $handle): string;
}
