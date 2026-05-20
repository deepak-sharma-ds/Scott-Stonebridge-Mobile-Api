<?php

declare(strict_types=1);

namespace App\Jobs\Sales;

use App\Contracts\Services\Sales\StoreKnowledgeServiceInterface;
use App\Models\StoreKnowledge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;
use Throwable;

/**
 * Summarise a single knowledge item with gpt-4.1-mini and upsert into the
 * store_knowledge table. Summary capped at
 * config('sales.knowledge.item_summary_max_tokens') tokens.
 *
 * Idempotent: upsert keyed by (shop_domain, content_type, handle).
 */
class SummariseKnowledgeItemJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly string $shopDomain,
        public readonly string $contentType,
        public readonly string $title,
        public readonly string $handle,
        public readonly string $rawContent,
        public readonly ?string $shopifyUpdatedAt = null,
    ) {}

    public function handle(StoreKnowledgeServiceInterface $knowledge): void
    {
        if ($this->shopDomain === '' || $this->title === '' || $this->rawContent === '') {
            return;
        }

        $summary = $this->summarise($this->rawContent);

        StoreKnowledge::query()->updateOrCreate(
            [
                'shop_domain' => $this->shopDomain,
                'content_type' => $this->contentType,
                'handle' => $this->handle !== '' ? $this->handle : null,
            ],
            [
                'title' => $this->title,
                'summary' => $summary,
                'raw_content' => $this->rawContent,
                'last_synced_at' => now(),
                'shopify_updated_at' => $this->shopifyUpdatedAt !== null
                    ? Carbon::parse($this->shopifyUpdatedAt)
                    : null,
            ],
        );

        $knowledge->invalidateCache($this->shopDomain);
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('error')->error('SummariseKnowledgeItemJob failed', [
            'shop' => $this->shopDomain,
            'content_type' => $this->contentType,
            'handle' => $this->handle,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Call gpt-4.1-mini to produce a short summary. Falls back to a raw
     * truncation on any error so the row always lands populated.
     */
    private function summarise(string $rawContent): string
    {
        $maxTokens = (int) config('sales.knowledge.item_summary_max_tokens', 300);
        $stripped = trim(strip_tags($rawContent));
        $stripped = (string) preg_replace('/\s+/u', ' ', $stripped);

        // Cap input characters so the prompt cost stays bounded. 6000 chars
        // ~= 1500 tokens of input which is plenty for a page/article.
        $input = mb_strimwidth($stripped, 0, 6000, '…');

        try {
            $model = (string) config('chatbot.models.classifier', 'gpt-4.1-mini');
            $response = OpenAI::chat()->create([
                'model' => $model,
                'temperature' => 0,
                'max_tokens' => $maxTokens,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You summarise Shopify store content (pages, policies, blog articles) for use in a chat bot prompt. '
                            .'Reply with strict JSON: {"summary":"..."}. '
                            .'Keep to plain prose, no markdown, no headings. Maximum '.$maxTokens.' tokens.',
                    ],
                    [
                        'role' => 'user',
                        'content' => sprintf("Title: %s\nType: %s\n\nContent:\n%s", $this->title, $this->contentType, $input),
                    ],
                ],
            ]);

            $raw = $response->choices[0]->message->content ?? '{}';
            $decoded = json_decode((string) $raw, true);
            if (is_array($decoded) && isset($decoded['summary']) && is_string($decoded['summary'])) {
                return mb_strimwidth(trim($decoded['summary']), 0, $maxTokens * 4, '…');
            }
        } catch (Throwable $e) {
            Log::channel('ai')->warning('Knowledge summary OpenAI call failed; using truncation', [
                'shop' => $this->shopDomain,
                'handle' => $this->handle,
                'error' => $e->getMessage(),
            ]);
        }

        return mb_strimwidth($stripped, 0, $maxTokens * 4, '…');
    }
}
