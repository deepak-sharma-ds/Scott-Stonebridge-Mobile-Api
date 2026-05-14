<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiMessage>
 */
class AiMessageFactory extends Factory
{
    protected $model = AiMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => AiConversation::factory(),
            'role' => AiMessage::ROLE_USER,
            'message' => fake()->sentence(),
            'intent' => null,
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'latency_ms' => null,
            'metadata' => null,
        ];
    }

    public function user(): self
    {
        return $this->state(fn () => ['role' => AiMessage::ROLE_USER]);
    }

    public function assistant(): self
    {
        return $this->state(fn () => [
            'role' => AiMessage::ROLE_ASSISTANT,
            'prompt_tokens' => fake()->numberBetween(50, 500),
            'completion_tokens' => fake()->numberBetween(20, 300),
            'latency_ms' => fake()->numberBetween(200, 2500),
        ]);
    }

    public function system(): self
    {
        return $this->state(fn () => ['role' => AiMessage::ROLE_SYSTEM]);
    }
}
