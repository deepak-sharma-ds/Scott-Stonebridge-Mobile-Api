<?php

declare(strict_types=1);

namespace App\Exceptions\AI;

/**
 * Raised when the chatbot rate limiter rejects a request before reaching OpenAI.
 */
class AIRateLimitException extends AIException
{
    public function __construct(string $message = 'Too many chat requests. Please slow down.', array $context = [])
    {
        parent::__construct($message, 429, 'ai_rate_limited', $context);
    }
}
