<?php

namespace App\Contracts\Services;

use App\DTOs\Theme\ThemeTemplateDTO;

/**
 * Theme Service Interface
 * 
 * Defines the contract for Shopify theme template operations.
 * Provides access to theme templates for rendering dynamic content.
 * 
 * Uses Shopify Admin API to fetch theme template assets from Online Store 2.0.
 */
interface ThemeServiceInterface
{
    /**
     * Get a single theme template by handle
     * 
     * @param string $handle Template handle (e.g., "page", "page.about", "product.custom")
     * @return ThemeTemplateDTO
     */
    public function getTemplateByHandle(string $handle): ThemeTemplateDTO;

    /**
     * Get template by type and optional suffix
     * 
     * @param string $type Template type (product, collection, page, article, etc.)
     * @param string|null $suffix Optional template suffix for alternate templates
     * @return ThemeTemplateDTO
     */
    public function getTemplateByType(string $type, ?string $suffix = null): ThemeTemplateDTO;
}
