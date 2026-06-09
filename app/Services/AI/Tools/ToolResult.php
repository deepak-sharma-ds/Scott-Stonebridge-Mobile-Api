<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

/**
 * Outcome of a single tool invocation. Drives both the SSE chunk that gets
 * pushed to the browser and the `role:tool` message fed back to OpenAI for
 * the next loop iteration.
 */
final class ToolResult
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_ERROR = 'error';

    public const STATUS_AUTH_REQUIRED = 'auth_required';

    /**
     * @param  array<string, mixed>  $emittedChunk  Already pushed via ChunkEmitter; included for tests.
     */
    public function __construct(
        public readonly string $status,
        public readonly string $messageForAi,
        public readonly array $emittedChunk = [],
    ) {}

    /**
     * @param  array<string, mixed>  $emittedChunk
     */
    public static function success(string $messageForAi, array $emittedChunk = []): self
    {
        return new self(self::STATUS_SUCCESS, $messageForAi, $emittedChunk);
    }

    /**
     * @param  array<string, mixed>  $emittedChunk
     */
    public static function error(string $messageForAi, array $emittedChunk = []): self
    {
        return new self(self::STATUS_ERROR, $messageForAi, $emittedChunk);
    }

    /**
     * @param  array<string, mixed>  $emittedChunk
     */
    public static function authRequired(string $messageForAi, array $emittedChunk = []): self
    {
        return new self(self::STATUS_AUTH_REQUIRED, $messageForAi, $emittedChunk);
    }

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function isAuthRequired(): bool
    {
        return $this->status === self::STATUS_AUTH_REQUIRED;
    }
}
