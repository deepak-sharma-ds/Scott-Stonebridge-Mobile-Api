<?php

declare(strict_types=1);

namespace App\Exceptions\AI;

use Throwable;

/**
 * Raised when an upstream dependency (OpenAI, Shopify GraphQL) is unreachable
 * or returns a non-recoverable error.
 */
class AIServiceUnavailableException extends AIException
{
    public function __construct(string $message = 'AI service temporarily unavailable.', array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 503, 'ai_service_unavailable', $context, $previous);
    }
}
