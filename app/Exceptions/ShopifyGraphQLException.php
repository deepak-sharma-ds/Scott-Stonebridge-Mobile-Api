<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class ShopifyGraphQLException extends Exception
{
    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly ?array $userErrors = null,
        public readonly ?string $query = null,
    ) {
        parent::__construct($message);
    }
    
    /**
     * Check if exception has user errors
     */
    public function hasUserErrors(): bool
    {
        return !empty($this->userErrors);
    }
    
    /**
     * Get user error messages
     */
    public function getUserErrorMessages(): array
    {
        return array_map(
            fn($error) => $error['message'] ?? 'Unknown error',
            $this->userErrors ?? []
        );
    }
    
    /**
     * Get GraphQL error messages
     */
    public function getErrorMessages(): array
    {
        return array_map(
            fn($error) => $error['message'] ?? 'Unknown error',
            $this->errors
        );
    }
}
