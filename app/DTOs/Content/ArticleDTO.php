<?php

namespace App\DTOs\Content;

use App\DTOs\Base\BaseDTO;
use InvalidArgumentException;

/**
 * Article Data Transfer Object
 * 
 * Represents a Shopify blog article with content and metadata.
 * Articles are individual blog posts within a blog.
 * 
 * Requirements: 11.8, 11.11, 11.12
 */
class ArticleDTO extends BaseDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $handle,
        public readonly string $content,
        public readonly ?string $excerpt,
        public readonly ?string $image,
        public readonly array $tags,
        public readonly ?array $author,
        public readonly string $publishedAt,
    ) {
        $this->validate();
    }

    /**
     * Validate the article data.
     * 
     * @throws InvalidArgumentException
     */
    protected function validate(): void
    {
        $this->validateRequired($this->id, 'Article ID');
        $this->validateRequired($this->title, 'Title');
        $this->validateRequired($this->handle, 'Handle');
    }

    /**
     * Create an ArticleDTO from Shopify API response data.
     * 
     * Transforms raw Shopify GraphQL article response into a typed DTO instance.
     * Handles content, images, tags, and author information.
     * 
     * @param array $data Raw article data from Shopify GraphQL response
     * @return self
     */
    public static function fromShopifyResponse(array $data): self
    {
        // Extract image URL from various possible structures
        $image = null;
        if (isset($data['image']['url'])) {
            $image = $data['image']['url'];
        } elseif (isset($data['image']['originalSrc'])) {
            $image = $data['image']['originalSrc'];
        }

        // Extract author information if available
        $author = null;
        if (isset($data['author'])) {
            $author = [
                'name' => $data['author']['name'] ?? null,
                'email' => $data['author']['email'] ?? null,
            ];
        }

        return new self(
            id: $data['id'],
            title: $data['title'],
            handle: $data['handle'],
            content: $data['content'] ?? $data['contentHtml'] ?? '',
            excerpt: $data['excerpt'] ?? $data['excerptHtml'] ?? null,
            image: $image,
            tags: $data['tags'] ?? [],
            author: $author,
            publishedAt: $data['publishedAt'],
        );
    }
}
