<?php

declare(strict_types=1);

namespace App\DTOs\Chat;

use App\DTOs\Base\BaseDTO;

/**
 * Result of an AI completion call. Used for the non-streamed `/message`
 * endpoint and for the final assistant message persisted after a stream
 * completes.
 */
class AIResponseDTO extends BaseDTO
{
    /**
     * @param  list<ProductRecommendationDTO>  $products
     * @param  array<string, mixed>  $usage  ['prompt_tokens'=>int,'completion_tokens'=>int,'total_tokens'=>int]
     */
    public function __construct(
        public readonly string $content,
        public readonly string $intent,
        public readonly array $products,
        public readonly array $usage,
        public readonly int $latencyMs,
        public readonly string $model,
        public readonly ?string $finishReason,
    ) {
        $this->validate();
    }

    protected function validate(): void
    {
        $this->validateNonNegative($this->latencyMs, 'latencyMs');
    }

    public function promptTokens(): int
    {
        return (int) ($this->usage['prompt_tokens'] ?? 0);
    }

    public function completionTokens(): int
    {
        return (int) ($this->usage['completion_tokens'] ?? 0);
    }
}
