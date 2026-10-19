<?php

namespace App\Services\Bundles;

use RuntimeException;

class ShopifyApiException extends RuntimeException
{
    public function __construct(string $message, protected array $userErrors = [], protected ?string $operation = null)
    {
        parent::__construct($message);
    }

    public function userErrors(): array
    {
        return $this->userErrors;
    }

    public function operation(): ?string
    {
        return $this->operation;
    }
}
