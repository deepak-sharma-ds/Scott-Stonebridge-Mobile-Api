<?php

namespace App\Contracts\Services;

use App\DTOs\Content\ArticleDTO;
use App\DTOs\Content\MediaImageDTO;
use App\DTOs\Content\PageDTO;

/**
 * Content Service Interface
 * 
 * Defines the contract for content management operations including
 * pages, blogs, articles, and policies.
 */
interface ContentServiceInterface
{
    /**
     * Get page by handle
     * 
     * @param string $handle Page handle
     * @return PageDTO
     */
    public function getPageByHandle(string $handle): PageDTO;

    /**
     * Get policy by type
     * 
     * @param string $type Policy type (privacy, refund, shipping, terms)
     * @return PageDTO
     */
    public function getPolicyByType(string $type): PageDTO;

    /**
     * Get blogs with pagination
     * 
     * @param int $limit Number of blogs to retrieve
     * @param string|null $cursor Pagination cursor
     * @return array ['items' => BlogDTO[], 'pagination' => array]
     */
    public function getBlogs(int $limit = 10, ?string $cursor = null): array;

    /**
     * Get articles for a blog with pagination
     * 
     * @param string $blogHandle Blog handle
     * @param int $limit Number of articles to retrieve
     * @param string|null $cursor Pagination cursor
     * @return array ['items' => ArticleDTO[], 'pagination' => array]
     */
    public function getArticles(string $blogHandle, int $limit = 10, ?string $cursor = null): array;

    /**
     * Get a single article
     * 
     * @param string $blogHandle Blog handle
     * @param string $articleHandle Article handle
     * @return ArticleDTO
     */
    public function getArticle(string $blogHandle, string $articleHandle): ArticleDTO;

    /**
     * Get a single Shopify media image by ID.
     *
     * @param string $id Shopify media image GID
     * @return MediaImageDTO
     */
    public function getMediaImage(string $id): MediaImageDTO;

    /**
     * Resolve URL to resource type
     * 
     * @param string $url URL to resolve
     * @return array ['type' => string, 'handle' => string|null, ...]
     */
    public function resolveUrl(string $url): array;
}
