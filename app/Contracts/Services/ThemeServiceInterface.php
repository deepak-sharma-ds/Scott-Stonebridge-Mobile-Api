<?php

namespace App\Contracts\Services;

use App\DTOs\Theme\ThemeTemplateDTO;

/**
 * Theme Service Interface
 * 
 * Defines the contract for Shopify theme template operations.
 * Provides access to theme templates for rendering dynamic content.
 */
interface ThemeServiceInterface
{
    /**
     * Get theme templates with pagination
     * 
     * @param int $limit Number of templates to retrieve
     * @param string|null $cursor Pagination cursor
     * @return array ['items' => ThemeTemplateDTO[], 'pagination' => array]
     */
    public function getTemplates(int $limit = 10, ?string $cursor = null): array;

    /**
     * Get a single theme template by handle
     * 
     * @param string $handle Template handle
     * @return ThemeTemplateDTO
     */
    public function getTemplateByHandle(string $handle): ThemeTemplateDTO;

    /**
     * Get template by type and resource
     * 
     * @param string $type Template type (product, collection, page, article, etc.)
     * @param string|null $resourceHandle Optional resource handle for specific templates
     * @return ThemeTemplateDTO
     */
    public function getTemplateByType(string $type, ?string $resourceHandle = null): ThemeTemplateDTO;
}
