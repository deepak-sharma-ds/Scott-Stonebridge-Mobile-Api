<?php

namespace App\DTOs\Theme;

use App\DTOs\Base\BaseDTO;
use InvalidArgumentException;

/**
 * Theme Template Data Transfer Object
 * 
 * Represents a Shopify theme template with metadata and content.
 * Used for rendering dynamic theme-based content in the mobile API.
 */
class ThemeTemplateDTO extends BaseDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $handle,
        public readonly string $type,
        public readonly string $name,
        public readonly ?string $suffix,
        public readonly array $sections,
        public readonly array $settings,
        public readonly ?array $order,
        public readonly ?array $metadata,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
        $this->validate();
    }

    /**
     * Validate the theme template data.
     * 
     * @throws InvalidArgumentException
     */
    protected function validate(): void
    {
        $this->validateRequired($this->id, 'Template ID');
        $this->validateRequired($this->handle, 'Handle');
        $this->validateRequired($this->type, 'Type');
        $this->validateRequired($this->name, 'Name');
    }

    /**
     * Create a ThemeTemplateDTO from Shopify API response data.
     * 
     * @param array $data Raw template data from Shopify Admin API response
     * @return self
     */
    public static function fromShopifyResponse(array $data): self
    {
        return new self(
            id: $data['id'],
            handle: $data['handle'],
            type: $data['type'] ?? 'default',
            name: $data['name'] ?? $data['handle'],
            suffix: $data['suffix'] ?? null,
            sections: $data['sections'] ?? [],
            settings: $data['settings'] ?? [],
            order: $data['order'] ?? null,
            metadata: $data['metadata'] ?? null,
            createdAt: $data['createdAt'] ?? now()->toIso8601String(),
            updatedAt: $data['updatedAt'] ?? now()->toIso8601String(),
        );
    }
}
