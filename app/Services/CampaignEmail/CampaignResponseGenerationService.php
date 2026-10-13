<?php

declare(strict_types=1);

namespace App\Services\CampaignEmail;

use App\Models\CampaignProduct;
use App\Models\CampaignProductResponse;
use App\Support\GeneratedBodyValidationResult;
use App\Support\GeneratedBodyValidator;
use App\Support\MarkdownMailBody;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;
use RuntimeException;
use Throwable;

/**
 * Generates the single pre-generated marketing email response for a
 * (campaign, product) pairing via OpenAI. Mirrors the
 * App\Services\EmailReading\EmailReadingGenerationService pattern: one
 * private `complete()` call shared by the persisting and preview paths.
 *
 * Unlike the reading flow, this is never called at order/purchase time —
 * only from an admin-triggered "generate"/"regenerate" action, ahead of the
 * campaign being sent.
 */
class CampaignResponseGenerationService
{
    /**
     * Generate and persist the response for a campaign product pairing.
     * A prior response for the same pairing is replaced, never duplicated
     * (enforced by the unique campaign_product_id FK on
     * campaign_product_responses).
     */
    public function generate(CampaignProduct $campaignProduct): void
    {
        $start = microtime(true);

        try {
            $preview = $this->preview($campaignProduct);
        } catch (Throwable $e) {
            Log::channel('shopify_webhooks')->error('Campaign response OpenAI call failed', [
                'campaign_product_id' => $campaignProduct->id,
                'model' => $campaignProduct->model ?: (string) config('campaign_email.openai_model'),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        $campaignProduct->response()->updateOrCreate(
            ['campaign_product_id' => $campaignProduct->id],
            [
                'source' => CampaignProductResponse::SOURCE_AI,
                'body' => $preview['content'],
                'prompt_tokens' => $preview['prompt_tokens'],
                'completion_tokens' => $preview['completion_tokens'],
                'model_used' => $preview['model'],
                'generated_at' => Carbon::now(),
            ]
        );

        Log::channel('shopify_webhooks')->info('Campaign response generated', [
            'campaign_product_id' => $campaignProduct->id,
            'model' => $preview['model'],
            'latency_ms' => $latencyMs,
            'completion_tokens' => $preview['completion_tokens'],
        ]);
    }

    /**
     * Generate a response for a pairing WITHOUT persisting it. Used by an
     * admin "preview" / "generate with AI" action before committing to accept.
     *
     * @return array{content:string,prompt_tokens:int,completion_tokens:int,model:string,finish_reason:string,problems:list<string>,is_valid:bool}
     */
    public function preview(
        CampaignProduct $campaignProduct,
        ?string $promptTemplateOverride = null,
        ?string $formattingContractOverride = null
    ): array {
        $model = $campaignProduct->model ?: (string) config('campaign_email.openai_model');
        $maxTokens = (int) ($campaignProduct->max_tokens ?: config('campaign_email.max_tokens', 1500));

        $prompt = $this->renderPrompt($campaignProduct, $promptTemplateOverride);
        $systemPrompt = $this->buildSystemPrompt($campaignProduct, $formattingContractOverride);

        $result = $this->complete($systemPrompt, $prompt, $model, $maxTokens);
        $html = MarkdownMailBody::toHtml($result['content']);

        $contract = $this->resolveFormattingContract($campaignProduct, $formattingContractOverride);
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
     * Single OpenAI call shared by generate/preview. Returns the trimmed
     * content + usage; never touches the database.
     *
     * @return array{content:string,prompt_tokens:int,completion_tokens:int,model:string,finish_reason:string}
     */
    private function complete(string $system, string $user, string $model, int $maxTokens): array
    {
        $response = OpenAI::chat()->create([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
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
     * Resolves the active formatting contract for the product or the fallback.
     */
    public function resolveFormattingContract(
        ?CampaignProduct $campaignProduct = null,
        ?string $formattingContractOverride = null
    ): string {
        if (filled($formattingContractOverride)) {
            return trim((string) $formattingContractOverride);
        }

        return filled($campaignProduct?->formatting_contract)
            ? trim((string) $campaignProduct->formatting_contract)
            : (string) config('campaign_email.formatting_contract');
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

    /**
     * Validates a response body (HTML or markdown) against the product's formatting contract.
     */
    public function validateBody(
        CampaignProduct $campaignProduct,
        string $body,
        ?string $formattingContractOverride = null
    ): GeneratedBodyValidationResult {
        $contract = $this->resolveFormattingContract($campaignProduct, $formattingContractOverride);
        $expectedClosing = $this->resolveExpectedClosingSection($contract);

        return GeneratedBodyValidator::validate($body, 'stop', $expectedClosing);
    }

    private function renderPrompt(CampaignProduct $campaignProduct, ?string $promptTemplateOverride = null): string
    {
        $template = filled($promptTemplateOverride)
            ? (string) $promptTemplateOverride
            : ($campaignProduct->prompt_template
                ?: 'Write a marketing email promoting "{{ $productTitle }}" as part of the "{{ $campaignName }}" campaign.');

        $data = [
            'campaignName' => $campaignProduct->marketingCampaign?->name ?? '',
            'productTitle' => $campaignProduct->product_title ?? (string) $campaignProduct->shopify_product_id,
        ];

        return Blade::render($template, $data);
    }

    /**
     * Builds the full system prompt composed of Scott's base persona, the
     * formatting contract (product override or shared default), and voice rules.
     */
    public function buildSystemPrompt(
        ?CampaignProduct $campaignProduct = null,
        ?string $formattingContractOverride = null
    ): string {
        $base = $this->systemPrompt();
        $contract = $this->resolveFormattingContract($campaignProduct, $formattingContractOverride);
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
        $rules = config('campaign_email.voice_rules', []);

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
            'campaign_email.system_prompt',
            'You are Scott Stonebridge, an award-winning UK psychic medium. '
            .'Write a warm, compelling marketing email promoting the given product. '
            .'Do not include a greeting/salutation (e.g. "Dear ...") or a sign-off (e.g. "Warm blessings, ...") — '
            .'output only the body of the message itself, since the surrounding email template already supplies those.'
        );
    }
}
