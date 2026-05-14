<?php

declare(strict_types=1);

namespace App\Exceptions\AI;

/**
 * Raised when sanitization detects prompt-injection / jailbreak / banned content.
 */
class AISafetyViolationException extends AIException
{
    public function __construct(string $message = 'Message blocked by safety filters.', array $context = [])
    {
        parent::__construct($message, 422, 'ai_safety_violation', $context);
    }
}
