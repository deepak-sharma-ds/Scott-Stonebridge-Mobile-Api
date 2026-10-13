<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Result of validating an AI-generated or manually provided response body.
 */
class GeneratedBodyValidationResult
{
    /**
     * @param  list<string>  $problems  Human-readable descriptions of validation failures
     * @param  bool  $isTruncated  Whether the generation was cut off by token limits
     */
    public function __construct(
        public readonly array $problems = [],
        public readonly bool $isTruncated = false,
    ) {}

    public function isValid(): bool
    {
        return empty($this->problems);
    }

    public function isInvalid(): bool
    {
        return ! $this->isValid();
    }

    /**
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    public function isTruncated(): bool
    {
        return $this->isTruncated;
    }

    public function hasProblem(string $problem): bool
    {
        return in_array($problem, $this->problems, true);
    }
}
