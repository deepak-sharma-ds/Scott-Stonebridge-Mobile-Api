<?php

namespace App\DTOs\Collection;

use App\DTOs\Base\BaseDTO;
use InvalidArgumentException;

/**
 * Collection Data Transfer Object
 * 
 * Represents a Shopify collection with typed properties and validation.
 * Collections are groups of products organized by theme, category, or other criteria.
 * 
 * Requirements: 16.5, 16.6, 16.7
 */
class CollectionDTO extends BaseDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $handle,
        public readonly ?string $description,
        public readonly ?array $image,
        public readonly ?string $updatedAt,
    ) {
        $this->validate();
    }

    /**
     * Validate the collection data.
     * 
     * @throws InvalidArgumentException
     */
    protected function validate(): void
    {
        $this->validateRequired($this->id, 'Collection ID');
        $this->validateRequired($this->title, 'Collection title');
        $this->validateRequired($this->handle, 'Collection handle');
    }

    /**
     * Create a CollectionDTO from Shopify API response data.
     * 
     * Transforms raw Shopify GraphQL collection response into a typed DTO instance.
     * Handles both simple collection lists and detailed collection data.
     * 
     * @param array $data Raw collection data from Shopify GraphQL response
     * @return self
     */
    public static function fromShopifyResponse(array $data): self
    {
        // Handle image data - can be null, or have different structures
        $image = null;
        if (isset($data['image'])) {
            $image = [
                'url' => $data['image']['url'] ?? $data['image']['originalSrc'] ?? '',
                'alt' => $data['image']['altText'] ?? null,
            ];
        }

        return new self(
            id: $data['id'],
            title: $data['title'],
            handle: $data['handle'],
            description: $data['description'] ?? null,
            image: $image,
            updatedAt: $data['updatedAt'] ?? null,
        );
    }
}
