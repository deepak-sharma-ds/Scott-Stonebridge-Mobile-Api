<?php

declare(strict_types=1);

namespace App\DTOs\Chat;

use App\DTOs\Base\BaseDTO;
use InvalidArgumentException;

/**
 * Full product payload returned by the Storefront MCP `get_product` tool.
 * Powers the in-chat product detail card with the variant picker.
 *
 * @phpstan-type VariantShape array{id:string,title:?string,price_minor_units:?int,available:bool,sku:?string,options:array<string,string>}
 * @phpstan-type ImageShape array{url:string,alt:?string}
 */
class ProductDetailDTO extends BaseDTO
{
    /**
     * @param  list<ImageShape>  $images
     * @param  list<VariantShape>  $variants
     * @param  list<string>  $tags
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $handle,
        public readonly ?string $descriptionHtml,
        public readonly array $images,
        public readonly array $variants,
        public readonly ?int $priceMinorUnits,
        public readonly ?string $currency,
        public readonly ?string $vendor,
        public readonly array $tags,
    ) {
        $this->validate();
    }

    protected function validate(): void
    {
        $this->validateRequired($this->id, 'id');
        $this->validateRequired($this->title, 'title');
        $this->validateRequired($this->handle, 'handle');

        foreach ($this->variants as $variant) {
            if (! isset($variant['id']) || ! is_string($variant['id']) || $variant['id'] === '') {
                throw new InvalidArgumentException('Each variant requires a non-empty id.');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'handle' => $this->handle,
            'description_html' => $this->descriptionHtml,
            'images' => $this->images,
            'variants' => $this->variants,
            'price_minor_units' => $this->priceMinorUnits,
            'currency' => $this->currency,
            'vendor' => $this->vendor,
            'tags' => $this->tags,
        ];
    }
}
