<?php

namespace App\DTOs\Content;

use App\DTOs\Base\BaseDTO;
use InvalidArgumentException;

/**
 * Media Image Data Transfer Object
 *
 * Represents a Shopify MediaImage node resolved from a file/media reference.
 */
class MediaImageDTO extends BaseDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $url,
        public readonly ?string $altText,
        public readonly ?int $width,
        public readonly ?int $height,
    ) {
        $this->validate();
    }

    /**
     * Validate the media image data.
     *
     * @throws InvalidArgumentException
     */
    protected function validate(): void
    {
        $this->validateRequired($this->id, 'Media image ID');
        $this->validateRequired($this->url, 'Media image URL');
    }

    /**
     * Create a MediaImageDTO from Shopify API response data.
     *
     * @param array $data Raw media image node from Shopify GraphQL response
     * @return self
     */
    public static function fromShopifyResponse(array $data): self
    {
        $image = $data['image'] ?? [];

        return new self(
            id: $data['id'] ?? '',
            url: $image['url'] ?? '',
            altText: $image['altText'] ?? null,
            width: isset($image['width']) ? (int) $image['width'] : null,
            height: isset($image['height']) ? (int) $image['height'] : null,
        );
    }
}
