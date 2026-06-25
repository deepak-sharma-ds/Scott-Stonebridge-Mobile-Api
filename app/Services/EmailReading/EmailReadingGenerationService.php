<?php

declare(strict_types=1);

namespace App\Services\EmailReading;

use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
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

        $start = microtime(true);

        try {
            $result = $this->complete($this->systemPrompt(), $prompt, $model, $maxTokens);
        } catch (Throwable $e) {
            Log::channel('shopify_webhooks')->error('Email reading OpenAI call failed', [
                'delivery_id' => $delivery->id,
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        $delivery->forceFill([
            'ai_response' => $result['content'],
            'prompt_tokens' => $result['prompt_tokens'],
            'completion_tokens' => $result['completion_tokens'],
            'model_used' => $result['model'],
            'status' => EmailReadingDelivery::STATUS_GENERATED,
            'error_message' => null,
        ])->save();

        Log::channel('shopify_webhooks')->info('Email reading generated', [
            'delivery_id' => $delivery->id,
            'model' => $result['model'],
            'latency_ms' => $latencyMs,
            'completion_tokens' => $delivery->completion_tokens,
        ]);
    }

    /**
     * Generate a fresh reading for a delivery WITHOUT persisting it.
     *
     * Used by the admin "regenerate" tool: returns the proposed text so the
     * admin can review/edit before saving. When $adminInstruction is given it
     * is appended as an extra user turn so the model revises accordingly.
     *
     * @return array{content:string,prompt_tokens:int,completion_tokens:int,model:string}
     */
    public function previewForDelivery(EmailReadingDelivery $delivery, ?string $adminInstruction = null): array
    {
        $product = $delivery->product;

        if (! $product) {
            throw new RuntimeException('Email reading product missing for delivery '.$delivery->id);
        }

        $model = $product->model ?: (string) config('email_reading.openai_model');
        $maxTokens = (int) ($product->max_tokens ?: config('email_reading.max_tokens', 1500));

        $prompt = $this->renderPrompt($product->prompt_template, $delivery);

        return $this->complete($this->systemPrompt(), $prompt, $model, $maxTokens, $adminInstruction);
    }

    /**
     * Generate a preview reading for a product using sample answers, WITHOUT a
     * delivery row and WITHOUT persisting. Used by the product prompt-test tool.
     *
     * @param  array<string,mixed>  $answers
     * @return array{content:string,prompt_tokens:int,completion_tokens:int,model:string}
     */
    public function testForProduct(EmailReadingProduct $product, array $answers, ?string $customerName = null): array
    {
        $model = $product->model ?: (string) config('email_reading.openai_model');
        $maxTokens = (int) ($product->max_tokens ?: config('email_reading.max_tokens', 1500));

        $data = array_merge(
            ['customer_name' => $customerName ?: 'Dear Friend'],
            $answers
        );

        $prompt = Blade::render($product->prompt_template, $data);

        return $this->complete($this->systemPrompt(), $prompt, $model, $maxTokens);
    }

    /**
     * Single OpenAI call shared by generate/preview/test. Returns the trimmed
     * content + usage; never touches the database.
     *
     * @return array{content:string,prompt_tokens:int,completion_tokens:int,model:string}
     */
    private function complete(string $system, string $user, string $model, int $maxTokens, ?string $adminInstruction = null): array
    {
        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];

        if (! empty(trim((string) $adminInstruction))) {
            $messages[] = [
                'role' => 'user',
                'content' => 'Revise the email reading according to this instruction, '
                    .'keeping the same warm voice and sign-off: '.trim((string) $adminInstruction),
            ];
        }

        $response = OpenAI::chat()->create([
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => $maxTokens,
        ]);

        $choice = $response->choices[0] ?? null;
        $content = trim((string) ($choice->message->content ?? ''));

        if ($content === '') {
            throw new RuntimeException('OpenAI returned empty content.');
        }

        return [
            'content' => $content,
            'prompt_tokens' => $response->usage->promptTokens ?? 0,
            'completion_tokens' => $response->usage->completionTokens ?? 0,
            'model' => $model,
        ];
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
