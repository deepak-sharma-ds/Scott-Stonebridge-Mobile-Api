<?php

declare(strict_types=1);

namespace App\Services\CampaignEmail;

use App\Models\CampaignProduct;
use App\Models\CampaignProductResponse;
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
        $model = $campaignProduct->model ?: (string) config('campaign_email.openai_model');
        $maxTokens = (int) ($campaignProduct->max_tokens ?: config('campaign_email.max_tokens', 1500));

        $prompt = $this->renderPrompt($campaignProduct);

        $start = microtime(true);

        try {
            $result = $this->complete($this->systemPrompt(), $prompt, $model, $maxTokens);
        } catch (Throwable $e) {
            Log::channel('shopify_webhooks')->error('Campaign response OpenAI call failed', [
                'campaign_product_id' => $campaignProduct->id,
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        $campaignProduct->response()->updateOrCreate(
            ['campaign_product_id' => $campaignProduct->id],
            [
                'source' => CampaignProductResponse::SOURCE_AI,
                'body' => $result['content'],
                'prompt_tokens' => $result['prompt_tokens'],
                'completion_tokens' => $result['completion_tokens'],
                'model_used' => $result['model'],
                'generated_at' => Carbon::now(),
            ]
        );

        Log::channel('shopify_webhooks')->info('Campaign response generated', [
            'campaign_product_id' => $campaignProduct->id,
            'model' => $result['model'],
            'latency_ms' => $latencyMs,
            'completion_tokens' => $result['completion_tokens'],
        ]);
    }

    /**
     * Generate a response for a pairing WITHOUT persisting it. Used by an
     * admin "preview" action before committing to generate().
     *
     * @return array{content:string,prompt_tokens:int,completion_tokens:int,model:string}
     */
    public function preview(CampaignProduct $campaignProduct): array
    {
        $model = $campaignProduct->model ?: (string) config('campaign_email.openai_model');
        $maxTokens = (int) ($campaignProduct->max_tokens ?: config('campaign_email.max_tokens', 1500));

        $prompt = $this->renderPrompt($campaignProduct);

        return $this->complete($this->systemPrompt(), $prompt, $model, $maxTokens);
    }

    /**
     * Single OpenAI call shared by generate/preview. Returns the trimmed
     * content + usage; never touches the database.
     *
     * @return array{content:string,prompt_tokens:int,completion_tokens:int,model:string}
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
        ];
    }

    private function renderPrompt(CampaignProduct $campaignProduct): string
    {
        $template = $campaignProduct->prompt_template
            ?: 'Write a marketing email promoting "{{ $productTitle }}" as part of the "{{ $campaignName }}" campaign.';

        $data = [
            'campaignName' => $campaignProduct->marketingCampaign?->name ?? '',
            'productTitle' => $campaignProduct->product_title ?? (string) $campaignProduct->shopify_product_id,
        ];

        return Blade::render($template, $data);
    }

    private function systemPrompt(): string
    {
        return (string) config(
            'campaign_email.system_prompt',
            'You are Scott Stonebridge, an award-winning UK psychic medium. '
            .'Write a warm, compelling marketing email promoting the given product, addressed to the customer. '
            .'Use clear paragraphs, no headings, no markdown. Sign off as "Warm blessings, Scott Stonebridge".'
        );
    }
}
