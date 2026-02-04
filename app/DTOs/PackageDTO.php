<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PackageDTO extends BaseDTO
{
    public function __construct(
        public ?int $id,
        public string $title,
        public ?string $description,
        public float $price,
        public string $currency,
        public ?string $shopifyTag,
        public ?string $coverImage,
        public string $status,
    ) {}
}
