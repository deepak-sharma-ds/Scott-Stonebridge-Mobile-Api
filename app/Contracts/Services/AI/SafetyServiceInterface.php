<?php

declare(strict_types=1);

namespace App\Contracts\Services\AI;

use App\Exceptions\AI\AIRateLimitException;
use App\Exceptions\AI\AISafetyViolationException;

interface SafetyServiceInterface
{
    /**
     * Strip HTML, control characters, and normalize unicode. Caps length to
     * the configured max. Returns the sanitized string.
     */
    public function sanitize(string $message): string;

    /**
     * Run safety checks on a sanitized message. Throws an
     * AISafetyViolationException when banned patterns match.
     *
     * @throws AISafetyViolationException
     */
    public function assertSafe(string $sanitizedMessage): void;

    /**
     * Apply the per-session + per-IP cooldown counters in Redis.
     *
     * @throws AIRateLimitException
     */
    public function assertWithinLimits(string $sessionId, ?string $ip): void;
}
