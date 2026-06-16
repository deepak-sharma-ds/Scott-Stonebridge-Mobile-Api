<?php

declare(strict_types=1);

namespace App\Services\EmailReading;

use App\Models\EmailReadingDelivery;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;
use RuntimeException;
use Throwable;

/**
 * Generates an email reading via OpenAI for a given delivery row.
 *
 * Mirrors the App\Services\AI\AIResponseService wrapper pattern but is
 * dedicated to long-form reading output (higher max_tokens, per-product
 * model override, no chat-history coupling).
 */
class EmailReadingGenerationService
{
    public function generate(EmailReadingDelivery $delivery): void
    {
        $product = $delivery->product;

        if (! $product) {
            throw new RuntimeException('Email reading product missing for delivery '.$delivery->id);
        }

        $model = $product->model ?: (string) config('email_reading.openai_model');
        $maxTokens = (int) ($product->max_tokens ?: config('email_reading.max_tokens', 1500));

        $prompt = $this->renderPrompt($product->prompt_template, $delivery);

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $prompt],
        ];

        $start = microtime(true);

        try {
            $response = OpenAI::chat()->create([
                'model' => $model,
                'messages' => $messages,
                'temperature' => 0.7,
                'max_tokens' => $maxTokens,
            ]);
        } catch (Throwable $e) {
            Log::channel('shopify_webhooks')->error('Email reading OpenAI call failed', [
                'delivery_id' => $delivery->id,
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $latencyMs = (int) round((microtime(true) - $start) * 1000);
        $choice = $response->choices[0] ?? null;
        $content = trim((string) ($choice->message->content ?? ''));

        if ($content === '') {
            throw new RuntimeException('OpenAI returned empty content for delivery '.$delivery->id);
        }

        $delivery->forceFill([
            'ai_response' => $content,
            'prompt_tokens' => $response->usage->promptTokens ?? 0,
            'completion_tokens' => $response->usage->completionTokens ?? 0,
            'model_used' => $model,
            'status' => EmailReadingDelivery::STATUS_GENERATED,
            'error_message' => null,
        ])->save();

        Log::channel('shopify_webhooks')->info('Email reading generated', [
            'delivery_id' => $delivery->id,
            'model' => $model,
            'latency_ms' => $latencyMs,
            'completion_tokens' => $delivery->completion_tokens,
        ]);
    }

    private function renderPrompt(string $template, EmailReadingDelivery $delivery): string
    {
        $data = array_merge(
            ['customer_name' => $delivery->customer_name ?? 'Dear Friend'],
            (array) $delivery->questions
        );

        return Blade::render($template, $data);
    }

    private function systemPrompt(): string
    {
        return (string) config(
            'email_reading.system_prompt',
            'You are Scott Stonebridge, an award-winning UK psychic medium. '
            .'Write a warm, compassionate, well-structured email reading addressed to the customer. '
            .'Use clear paragraphs, no headings, no markdown. Sign off as "Warm blessings, Scott Stonebridge".'
        );
    }
}
