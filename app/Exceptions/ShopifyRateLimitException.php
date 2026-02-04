<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class ShopifyRateLimitException extends Exception
{
    public function __construct(
        string $message = 'Shopify rate limit exceeded. Please try again later.',
        public readonly ?int $retryAfter = 60,
    ) {
        parent::__construct($message);
    }
}
