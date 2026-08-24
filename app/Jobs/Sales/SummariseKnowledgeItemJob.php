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
        // Chunking (ADR 0009): documentHandle is the shared, unsuffixed
        // handle every row of one document has in common — set on every
        // row dispatchKnowledgeItem() creates, chunked or not. chunkIndex
        // is this chunk's 0-based position, null when the document wasn't
        // split. See StoreKnowledgeService::dispatchKnowledgeItem().
        public readonly ?string $documentHandle = null,
        public readonly ?int $chunkIndex = null,
    ) {}

    public function handle(StoreKnowledgeServiceInterface $knowledge): void
    {
        if ($this->shopDomain === '' || $this->title === '' || $this->rawContent === '') {
            return;
        }

        $summary = $this->summarise($this->rawContent);

        // Embed the summary so the retrieval picker can rank by cosine
        // similarity. Best-effort: if the embeddings API fails the row
        // still saves with embedding=null and the keyword path keeps
        // working — `knowledge:embed --missing-only` can backfill later.
        $embedding = $this->embed("{$this->title}\n\n{$summary}");

        $attrs = [
            'title' => $this->title,
            'summary' => $summary,
            'raw_content' => $this->rawContent,
            'document_handle' => $this->documentHandle,
            'chunk_index' => $this->chunkIndex,
            'last_synced_at' => now(),
            'shopify_updated_at' => $this->shopifyUpdatedAt !== null
                ? Carbon::parse($this->shopifyUpdatedAt)
                : null,
        ];

        if ($embedding !== null) {
            $attrs['embedding'] = $embedding;
            $attrs['embedding_model'] = (string) config('sales.knowledge.embedding.model', 'text-embedding-3-small');
            $attrs['embedded_at'] = now();
        }

        StoreKnowledge::query()->updateOrCreate(
            [
                'shop_domain' => $this->shopDomain,
                'content_type' => $this->contentType,
                'handle' => $this->handle !== '' ? $this->handle : null,
            ],
            $attrs,
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

    /**
     * Generate an OpenAI embedding for the row text. Wrapped in
     * try/catch so an embeddings outage never blocks the summary
     * upsert path — the FULLTEXT layer keeps working in degraded mode.
     *
     * @return list<float>|null
     */
    private function embed(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        try {
            $model = (string) config('sales.knowledge.embedding.model', 'text-embedding-3-small');
            // Embeddings API caps at ~8k tokens; trim aggressively so
            // long product descriptions don't break the call.
            $input = mb_strimwidth($text, 0, 8000, '…');

            $response = OpenAI::embeddings()->create([
                'model' => $model,
                'input' => $input,
            ]);

            $vector = $response->embeddings[0]->embedding ?? null;
            if (! is_array($vector) || $vector === []) {
                return null;
            }

            return array_map('floatval', $vector);
        } catch (Throwable $e) {
            Log::channel('ai')->warning('Knowledge embedding generation failed', [
                'shop' => $this->shopDomain,
                'handle' => $this->handle,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
