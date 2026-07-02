<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\DTOs\Chat\IntentDTO;
use App\Services\AI\AIResponseService;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

/**
 * Guards that the non-streamed completion path reads its sampling temperature
 * and output budget from config rather than hardcoding them. Sentinel values
 * are used so the assertion cannot pass by coincidence.
 */
class AIResponseServiceTest extends TestCase
{
    public function test_temperature_and_max_tokens_come_from_config(): void
    {
        config([
            'chatbot.generation.temperature' => 0.33,
            'chatbot.tokens.output_budget' => 999,
        ]);

        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'Hello.'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7],
            ]),
        ]);

        $service = app(AIResponseService::class);
        $intent = new IntentDTO(IntentDTO::INTENT_UNKNOWN, 0.3, [], 'regex');

        $service->complete(
            [['role' => 'user', 'content' => 'hi']],
            $intent,
        );

        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            return $method === 'create'
                && $parameters['temperature'] === 0.33
                && $parameters['max_tokens'] === 999;
        });
    }
}
