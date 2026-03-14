<?php

namespace App\DTOs\Navigation;

use App\DTOs\Base\BaseDTO;

/**
 * Menu DTO
 * 
 * Represents a Shopify menu with its items
 */
class MenuDTO extends BaseDTO
{
    public function __construct(
        public readonly string $handle,
        public readonly string $title,
        public readonly array $items
    ) {
        $this->validate();
    }

    /**
     * Validate the DTO data
     * 
     * @return void
     */
    protected function validate(): void
    {
        if (empty($this->handle)) {
            throw new \InvalidArgumentException('Menu handle is required');
        }
    }

    /**
     * Create from Shopify API response
     * 
     * @param array $data
     * @return self
     */
    public static function fromShopifyResponse(array $data): self
    {
        $items = [];
        
        if (!empty($data['items'])) {
            foreach ($data['items'] as $item) {
                $items[] = MenuItemDTO::fromShopifyResponse($item);
            }
        }

        return new self(
            handle: $data['handle'] ?? '',
            title: $data['title'] ?? '',
            items: $items
        );
    }

    /**
     * Convert to array
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'title' => $this->title,
            'items' => array_map(fn($item) => $item->toArray(), $this->items),
        ];
    }
}
