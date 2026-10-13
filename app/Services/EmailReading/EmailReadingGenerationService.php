<?php

declare(strict_types=1);

namespace App\Services\EmailReading;

use App\Jobs\EmailReading\NotifyReadingFailureJob;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Support\GeneratedBodyValidationResult;
use App\Support\GeneratedBodyValidator;
use App\Support\MarkdownMailBody;
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
    /**
     * Generate and persist the reading.
     *
     * The write is a conditional UPDATE: it only lands while the delivery is
     * still pending/failed/generated. If the delivery was cancelled, sent or
     * claimed while OpenAI was running, the result is discarded instead of
     * silently resurrecting it.
     *
     * @return bool false when the delivery's state changed and nothing was saved
     */
    public function generate(EmailReadingDelivery $delivery): bool
    {
        $product = $delivery->product;

        if (! $product) {
            throw new RuntimeException('Email reading product missing for delivery '.$delivery->id);
        }

        $model = $product->model ?: (string) config('email_reading.openai_model');
        $maxTokens = (int) ($product->max_tokens ?: config('email_reading.max_tokens', 1500));

        $prompt = $this->renderPrompt($product->prompt_template, $delivery);
        $systemPrompt = $this->buildSystemPrompt($product);
        $contract = $this->resolveFormattingContract($product);
        $expectedClosing = $this->resolveExpectedClosingSection($contract);

        $start = microtime(true);

        $attempt = $this->generateAndValidate($delivery, $systemPrompt, $prompt, $model, $maxTokens, $expectedClosing);

        if ($attempt['validation']->isInvalid()) {
            Log::channel('shopify_webhooks')->warning('Email reading generation validation failed; retrying once', [
                'delivery_id' => $delivery->id,
                'finish_reason' => $attempt['result']['finish_reason'],
                'problems' => $attempt['validation']->problems(),
            ]);

            $retryMaxTokens = $attempt['validation']->isTruncated()
                ? min(8000, max($maxTokens + 500, (int) round($maxTokens * 1.5)))
                : $maxTokens;

            $attempt = $this->generateAndValidate($delivery, $systemPrompt, $prompt, $model, $retryMaxTokens, $expectedClosing);
        }

        if ($attempt['validation']->isInvalid()) {
            Log::channel('shopify_webhooks')->error('Email reading generation validation failed after retry', [
                'delivery_id' => $delivery->id,
                'finish_reason' => $attempt['result']['finish_reason'],
                'problems' => $attempt['validation']->problems(),
            ]);

            $reasonMessage = 'Validation failed: '.implode('; ', $attempt['validation']->problems());
            if ($delivery->markFailed($reasonMessage)) {
                NotifyReadingFailureJob::dispatch($delivery->id, $reasonMessage)
                    ->onConnection(config('email_reading.queue.connection'))
                    ->onQueue(config('email_reading.queue.mail'));
            }

            return false;
        }

        $result = $attempt['result'];
        $html = $attempt['html'];
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        $saved = EmailReadingDelivery::query()
            ->whereKey($delivery->id)
            ->whereIn('status', [
                EmailReadingDelivery::STATUS_PENDING,
                EmailReadingDelivery::STATUS_FAILED,
                EmailReadingDelivery::STATUS_GENERATED,
            ])
            ->update([
                'ai_response' => $html,
                'prompt_tokens' => $result['prompt_tokens'],
                'completion_tokens' => $result['completion_tokens'],
                'model_used' => $result['model'],
                'status' => EmailReadingDelivery::STATUS_GENERATED,
                'error_message' => null,
            ]);

        if ($saved !== 1) {
            Log::channel('shopify_webhooks')->warning('Email reading generated but delivery state changed; result discarded', [
                'delivery_id' => $delivery->id,
            ]);

            return false;
        }

        $delivery->refresh();

        Log::channel('shopify_webhooks')->info('Email reading generated', [
            'delivery_id' => $delivery->id,
            'model' => $result['model'],
            'finish_reason' => $result['finish_reason'],
            'latency_ms' => $latencyMs,
            'completion_tokens' => $delivery->completion_tokens,
        ]);

        return true;
    }

    /**
     * Executes OpenAI completion, converts markdown to HTML, and validates the output.
     *
     * @return array{result:array{content:string,prompt_tokens:int,completion_tokens:int,model:string,finish_reason:string},html:string,validation:GeneratedBodyValidationResult}
     */
    private function generateAndValidate(
        EmailReadingDelivery $delivery,
        string $systemPrompt,
        string $prompt,
        string $model,
        int $maxTokens,
        ?string $expectedClosing
    ): array {
        try {
            $result = $this->complete($systemPrompt, $prompt, $model, $maxTokens);
        } catch (Throwable $e) {
            Log::channel('shopify_webhooks')->error('Email reading OpenAI call failed', [
                'delivery_id' => $delivery->id,
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $html = MarkdownMailBody::toHtml($result['content']);
        $validation = GeneratedBodyValidator::validate($html, $result['finish_reason'], $expectedClosing);

        return [
            'result' => $result,
            'html' => $html,
            'validation' => $validation,
        ];
    }

    /**
     * Generate a fresh reading for a delivery WITHOUT persisting it.
     *
     * Used by the admin "regenerate" tool: returns the proposed text so the
     * admin can review/edit before saving. When $adminInstruction is given it
     * is appended as an extra user turn so the model revises accordingly.
     *
     * @return array{content:string,prompt_tokens:int,completion_tokens:int,model:string,finish_reason:string,problems:list<string>,is_valid:bool}
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

        $result = $this->complete($this->buildSystemPrompt($product), $prompt, $model, $maxTokens, $adminInstruction);
        $html = MarkdownMailBody::toHtml($result['content']);
        $contract = $this->resolveFormattingContract($product);
        $expectedClosing = $this->resolveExpectedClosingSection($contract);
        $validation = GeneratedBodyValidator::validate($html, $result['finish_reason'], $expectedClosing);

        return [
            'content' => $html,
            'prompt_tokens' => $result['prompt_tokens'],
            'completion_tokens' => $result['completion_tokens'],
            'model' => $result['model'],
            'finish_reason' => $result['finish_reason'],
            'problems' => $validation->problems(),
            'is_valid' => $validation->isValid(),
        ];
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

        $result = $this->complete($this->buildSystemPrompt($product), $prompt, $model, $maxTokens);
        $result['content'] = MarkdownMailBody::toHtml($result['content']);

        return $result;
    }

    /**
     * Single OpenAI call shared by generate/preview/test. Returns the trimmed
     * content + usage; never touches the database.
     *
     * @return array{content:string,prompt_tokens:int,completion_tokens:int,model:string,finish_reason:string}
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
                    .'keeping the same warm voice: '.trim((string) $adminInstruction),
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
            'finish_reason' => (string) ($choice->finishReason ?? 'stop'),
        ];
    }

    /**
     * Returns the active formatting contract for the product or the default fallback.
     */
    public function resolveFormattingContract(?EmailReadingProduct $product = null): string
    {
        return filled($product?->formatting_contract)
            ? trim((string) $product->formatting_contract)
            : (string) config('email_reading.formatting_contract');
    }

    /**
     * Extracts the designated closing section title from a formatting contract.
     */
    public function resolveExpectedClosingSection(string $contract): ?string
    {
        if (preg_match("/headed ['\"](?:###\s*)?([^'\"]+)['\"]/i", $contract, $matches)) {
            return trim($matches[1]);
        }

        if (stripos($contract, 'From My Heart to Yours') !== false) {
            return 'From My Heart to Yours';
        }

        return null;
    }

    private function renderPrompt(string $template, EmailReadingDelivery $delivery): string
    {
        $data = array_merge(
            ['customer_name' => $delivery->customer_name ?? 'Dear Friend'],
            (array) $delivery->questions
        );

        return Blade::render($template, $data);
    }

    /**
     * Builds the full system prompt composed of Scott's base persona, the
     * formatting contract (product override or shared default), and voice rules.
     */
    public function buildSystemPrompt(?EmailReadingProduct $product = null): string
    {
        $base = $this->systemPrompt();
        $contract = $this->resolveFormattingContract($product);
        $voiceRules = $this->voiceRulesPrompt();

        $parts = array_filter(
            [$base, $contract, $voiceRules],
            fn (mixed $part): bool => filled($part)
        );

        return implode("\n\n", $parts);
    }

    /**
     * Formats configured voice and tone rules as a bulleted markdown block.
     */
    private function voiceRulesPrompt(): string
    {
        $rules = config('email_reading.voice_rules', []);

        if (empty($rules)) {
            return '';
        }

        if (is_string($rules)) {
            return trim($rules);
        }

        $formatted = array_map(
            fn (mixed $rule): string => '- '.trim((string) $rule),
            (array) $rules
        );

        return "Voice rules:\n".implode("\n", $formatted);
    }

    private function systemPrompt(): string
    {
        return (string) config(
            'email_reading.system_prompt',
            'You are Scott Stonebridge, an award-winning UK psychic medium. '
            .'Write a warm, compassionate, well-structured email reading. '
            .'Do not include a greeting/salutation (e.g. "Dear ...") or a sign-off (e.g. "Warm blessings, ...") — '
            .'output only the body of the reading itself, since the surrounding email template already supplies those.'
        );
    }
}
