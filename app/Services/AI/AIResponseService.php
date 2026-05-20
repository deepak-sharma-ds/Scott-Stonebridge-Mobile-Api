<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\Services\AI\AIResponseServiceInterface;
use App\DTOs\Chat\AIResponseDTO;
use App\DTOs\Chat\IntentDTO;
use App\Exceptions\AI\AIServiceUnavailableException;
use App\Services\Base\BaseService;
use OpenAI\Laravel\Facades\OpenAI;
use Throwable;

/**
 * Wraps OpenAI chat completion calls. Returns a domain DTO so callers never
 * touch the openai-php SDK shape directly. Handles model selection +
 * timing + token tallying + structured error logging.
 */
class AIResponseService extends BaseService implements AIResponseServiceInterface
{
    public function complete(array $messages, IntentDTO $intent, array $products = []): AIResponseDTO
    {
        $model = (string) config('chatbot.models.default');
        $maxOutput = (int) config('chatbot.tokens.output_budget', 600);
        $start = microtime(true);

        try {
            $response = OpenAI::chat()->create([
                'model' => $model,
                'messages' => $messages,
                'temperature' => 0.4,
                'max_tokens' => $maxOutput,
            ]);
        } catch (Throwable $e) {
            $this->logErrorWithException('OpenAI chat.completions failed', $e, [
                'model' => $model,
                'intent' => $intent->name,
            ]);

            throw new AIServiceUnavailableException('AI provider call failed.', [
                'model' => $model,
            ], $e);
        }

        $latency = (int) round((microtime(true) - $start) * 1000);
        $choice = $response->choices[0] ?? null;
        $content = (string) ($choice->message->content ?? '');
        $finishReason = $choice->finishReason ?? null;

        $usage = [
            'prompt_tokens' => $response->usage->promptTokens ?? 0,
            'completion_tokens' => $response->usage->completionTokens ?? 0,
            'total_tokens' => $response->usage->totalTokens ?? 0,
        ];

        $this->logInfo('AI completion succeeded', [
            'model' => $model,
            'intent' => $intent->name,
            'latency_ms' => $latency,
            'usage' => $usage,
            'product_count' => count($products),
        ], 'ai');

        return new AIResponseDTO(
            content: $content,
            intent: $intent->name,
            products: $products,
            usage: $usage,
            latencyMs: $latency,
            model: $model,
            finishReason: $finishReason,
        );
    }
}
