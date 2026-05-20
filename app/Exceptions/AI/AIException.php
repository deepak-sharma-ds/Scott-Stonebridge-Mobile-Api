<?php

declare(strict_types=1);

namespace App\Exceptions\AI;

use RuntimeException;
use Throwable;

/**
 * Base exception for AI chatbot module.
 *
 * Lets handlers catch any AI-layer fault with a single type while subclasses
 * carry domain-specific HTTP status hints + machine-readable codes consumed
 * by controllers when rendering error envelopes.
 */
class AIException extends RuntimeException
{
    public function __construct(
        string $message = '',
        protected int $httpStatus = 500,
        protected string $errorCode = 'ai_error',
        protected array $errorContext = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function errorContext(): array
    {
        return $this->errorContext;
    }
}
