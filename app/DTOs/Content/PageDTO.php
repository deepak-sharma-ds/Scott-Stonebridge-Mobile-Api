<?php

namespace App\DTOs\Content;

use App\DTOs\Base\BaseDTO;
use InvalidArgumentException;

/**
 * Page Data Transfer Object
 * 
 * Represents a Shopify page with content and metadata.
 * Used for CMS pages and policy pages in the mobile API.
 * 
 * Requirements: 11.6, 11.11, 11.12
 */
class PageDTO extends BaseDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $handle,
        public readonly string $body,
        public readonly ?string $bodySummary,
        public readonly ?array $metadata,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
        $this->validate();
    }

    /**
     * Validate the page data.
     * 
     * @throws InvalidArgumentException
     */
    protected function validate(): void
    {
        $this->validateRequired($this->id, 'Page ID');
        $this->validateRequired($this->title, 'Title');
        $this->validateRequired($this->handle, 'Handle');
    }

    /**
     * Create a PageDTO from Shopify API response data.
     * 
     * Transforms raw Shopify GraphQL page response into a typed DTO instance.
     * Handles both regular pages and policy pages.
     * 
     * @param array $data Raw page data from Shopify GraphQL response
     * @return self
     */
    public static function fromShopifyResponse(array $data): self
    {
        return new self(
            id: $data['id'],
            title: $data['title'],
            handle: $data['handle'],
            body: $data['body'] ?? '',
            bodySummary: $data['bodySummary'] ?? null,
            metadata: $data['metadata'] ?? null,
            createdAt: $data['createdAt'] ?? now()->toIso8601String(),
            updatedAt: $data['updatedAt'] ?? now()->toIso8601String(),
        );
    }
}
