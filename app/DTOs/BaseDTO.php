<?php

declare(strict_types=1);

namespace App\DTOs;

abstract class BaseDTO
{
    /**
     * Convert DTO to array
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
    
    /**
     * Create DTO from array
     */
    public static function fromArray(array $data): static
    {
        return new static(...$data);
    }
}
