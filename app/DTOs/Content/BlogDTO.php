<?php

namespace App\DTOs\Content;

use App\DTOs\Base\BaseDTO;
use InvalidArgumentException;

/**
 * Blog Data Transfer Object
 * 
 * Represents a Shopify blog with basic information.
 * Blogs contain multiple articles and are used for content marketing.
 * 
 * Requirements: 11.7, 11.11, 11.12
 */
class BlogDTO extends BaseDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $handle,
    ) {
        $this->validate();
    }

    /**
     * Validate the blog data.
     * 
     * @throws InvalidArgumentException
     */
    protected function validate(): void
    {
        $this->validateRequired($this->id, 'Blog ID');
        $this->validateRequired($this->title, 'Title');
        $this->validateRequired($this->handle, 'Handle');
    }

    /**
     * Create a BlogDTO from Shopify API response data.
     * 
     * Transforms raw Shopify GraphQL blog response into a typed DTO instance.
     * 
     * @param array $data Raw blog data from Shopify GraphQL response
     * @return self
     */
    public static function fromShopifyResponse(array $data): self
    {
        return new self(
            id: $data['id'],
            title: $data['title'],
            handle: $data['handle'],
        );
    }
}
