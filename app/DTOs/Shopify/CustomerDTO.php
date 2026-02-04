<?php

declare(strict_types=1);

namespace App\DTOs\Shopify;

use App\DTOs\BaseDTO;
use Illuminate\Support\Carbon;

class CustomerDTO extends BaseDTO
{
    public function __construct(
        public string $id,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $email,
        public ?string $phone,
        public bool $acceptsMarketing = false,
        public array $tags = [],
        public ?string $defaultAddressId = null,
        public array $addresses = [],
        public ?Carbon $updatedAt = null,
        public ?Carbon $createdAt = null
    ) {}

    public static function fromShopifyNode(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            firstName: $data['firstName'] ?? null,
            lastName: $data['lastName'] ?? null,
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
            acceptsMarketing: $data['acceptsMarketing'] ?? false,
            tags: $data['tags'] ?? [],
            defaultAddressId: $data['defaultAddress']['id'] ?? null,
            addresses: isset($data['addresses']['edges']) 
                ? array_map(fn($edge) => $edge['node'], $data['addresses']['edges']) 
                : [],
            updatedAt: isset($data['updatedAt']) ? Carbon::parse($data['updatedAt']) : null,
            createdAt: isset($data['createdAt']) ? Carbon::parse($data['createdAt']) : null
        );
    }
}
