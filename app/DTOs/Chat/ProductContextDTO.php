<?php

declare(strict_types=1);

namespace App\DTOs\Chat;

use App\DTOs\Base\BaseDTO;

/**
 * Minimal Shopify product reference sent by the frontend when the chat is
 * opened on a product page. Used as input to the context resolver — full
 * product data is fetched on demand via Storefront GraphQL.
 *
 * @phpstan-type ProductContextArray array{id?: string|null, handle?: string|null, title?: string|null, vendor?: string|null, price?: float|string|null, tags?: array<int, string>, variants?: array<int, array<string, mixed>>}
 */
class ProductContextDTO extends BaseDTO
{
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $handle,
        public readonly ?string $title,
        public readonly ?string $vendor,
        public readonly ?string $price,
        public readonly array $tags,
        public readonly array $variants,
    ) {
        $this->validate();
    }

    protected function validate(): void
    {
        // All fields optional — frontend may submit partial data.
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: isset($data['id']) ? (string) $data['id'] : null,
            handle: isset($data['handle']) ? (string) $data['handle'] : null,
            title: isset($data['title']) ? (string) $data['title'] : null,
            vendor: isset($data['vendor']) ? (string) $data['vendor'] : null,
            price: isset($data['price']) ? (string) $data['price'] : null,
            tags: array_values(array_filter((array) ($data['tags'] ?? []), 'is_string')),
            variants: array_values((array) ($data['variants'] ?? [])),
        );
    }

    public function isEmpty(): bool
    {
        return $this->id === null && $this->handle === null && $this->title === null;
    }
}
