<?php

declare(strict_types=1);

namespace App\DTOs\Shopify;

use App\DTOs\BaseDTO;

final readonly class ImageDTO extends BaseDTO
{
    public function __construct(
        public string $url,
        public ?string $altText,
        public ?int $width,
        public ?int $height,
    ) {}
    
    /**
     * Create from Shopify image object
     */
    public static function fromShopifyNode(array $image): self
    {
        return new self(
            url: $image['url'],
            altText: $image['altText'] ?? null,
            width: $image['width'] ?? null,
            height: $image['height'] ?? null,
        );
    }
}
