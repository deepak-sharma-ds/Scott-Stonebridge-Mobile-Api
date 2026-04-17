<?php

namespace App\DTOs\Navigation;

use App\DTOs\Base\BaseDTO;
use App\Services\UrlMapperService;

/**
 * Menu Item DTO
 * 
 * Represents a single menu item with optional nested items
 * Automatically maps Shopify URLs to API endpoints
 */
class MenuItemDTO extends BaseDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $url,
        public readonly string $apiEndpoint,
        public readonly array $params,
        public readonly string $type,
        public readonly array $items = []
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
        if (empty($this->title)) {
            throw new \InvalidArgumentException('Menu item title is required');
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
        $nestedItems = [];
        
        if (!empty($data['items'])) {
            foreach ($data['items'] as $item) {
                $nestedItems[] = self::fromShopifyResponse($item);
            }
        }

        $url = $data['url'] ?? '';
        $type = $data['type'] ?? 'HTTP';

        // Map Shopify URL to API endpoint
        $mapping = UrlMapperService::mapToApiEndpoint($url, $type);

        return new self(
            id: $data['id'] ?? '',
            title: $data['title'] ?? '',
            url: $url, // Original Shopify URL
            apiEndpoint: $mapping['api_endpoint'],
            params: $mapping['params'],
            type: $type,
            items: $nestedItems
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
            'id' => $this->id,
            'title' => $this->title,
            'url' => $this->url,
            'api_endpoint' => $this->apiEndpoint,
            'params' => $this->params,
            'type' => $this->type,
            'items' => array_map(fn($item) => $item->toArray(), $this->items),
        ];
    }
}
