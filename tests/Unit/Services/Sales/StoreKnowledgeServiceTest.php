<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sales;

use App\Jobs\Sales\SummariseKnowledgeItemJob;
use App\Models\StoreKnowledge;
use App\Services\Sales\StoreKnowledgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Embeddings\CreateResponse;
use RuntimeException;
use Tests\Mocks\MockShopifyClient;
use Tests\TestCase;

/**
 * Unit coverage for StoreKnowledgeService.
 *
 *   - syncAll dispatches per-item jobs from the paginated Admin response.
 *   - getKnowledgeForPrompt picks rows by intent-type map + caches result.
 *   - upsertFaq creates / updates and busts the cache.
 */
class StoreKnowledgeServiceTest extends TestCase
{
    use RefreshDatabase;

    private MockShopifyClient $admin;

    private StoreKnowledgeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->admin = new MockShopifyClient;
        $this->service = new StoreKnowledgeService($this->admin);

        config([
            'sales.knowledge.admin_page_size' => 50,
            'sales.knowledge.cache_ttl' => 86400,
            'sales.knowledge.prompt_block_max_tokens' => 500,
            'sales.knowledge.intent_content_map' => [
                'refund_policy' => ['policy'],
                'product_support' => ['page', 'blog'],
            ],
        ]);
    }

    public function test_sync_all_dispatches_summarise_jobs_for_pages_articles_and_policies(): void
    {
        Queue::fake();

        $this->admin->mockResponse('admin/pages/list_pages', [
            'data' => [
                'pages' => [
                    'edges' => [[
                        'node' => [
                            'id' => 'gid://shopify/Page/1',
                            'title' => 'About us',
                            'handle' => 'about-us',
                            'body' => '<p>We make things.</p>',
                            'updatedAt' => '2026-01-01T00:00:00Z',
                        ],
                        'cursor' => 'cur1',
                    ]],
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'cur1'],
                ],
            ],
        ]);

        $this->admin->mockResponse('admin/blogs/list_articles', [
            'data' => [
                'articles' => [
                    'edges' => [[
                        'node' => [
                            'id' => 'gid://shopify/Article/1',
                            'title' => 'How to care for your reading',
                            'handle' => 'care-guide',
                            'body' => 'Long body text here.',
                            'updatedAt' => '2026-01-01T00:00:00Z',
                        ],
                        'cursor' => 'a1',
                    ]],
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'a1'],
                ],
            ],
        ]);

        $this->admin->mockResponse('admin/policies/get_all_policies', [
            'data' => [
                'shop' => [
                    'shopPolicies' => [
                        [
                            'id' => 'pol1',
                            'title' => 'Refund policy',
                            'type' => 'refund_policy',
                            'body' => 'Refunds within 14 days.',
                        ],
                    ],
                ],
            ],
        ]);

        $this->service->syncAll('demo.myshopify.com');

        Queue::assertPushed(
            SummariseKnowledgeItemJob::class,
            fn (SummariseKnowledgeItemJob $job): bool => $job->contentType === StoreKnowledge::TYPE_PAGE
                && $job->title === 'About us'
        );
        Queue::assertPushed(
            SummariseKnowledgeItemJob::class,
            fn (SummariseKnowledgeItemJob $job): bool => $job->contentType === StoreKnowledge::TYPE_BLOG
                && $job->title === 'How to care for your reading'
        );
        Queue::assertPushed(
            SummariseKnowledgeItemJob::class,
            fn (SummariseKnowledgeItemJob $job): bool => $job->contentType === StoreKnowledge::TYPE_POLICY
                && $job->title === 'Refund policy'
        );
    }

    public function test_sync_all_is_noop_for_empty_shop(): void
    {
        Queue::fake();
        $this->service->syncAll('');
        Queue::assertNothingPushed();
    }

    public function test_get_knowledge_for_prompt_returns_empty_for_unknown_intents(): void
    {
        StoreKnowledge::factory()->forShop('demo.myshopify.com')->policy()->create();

        $this->assertSame('', $this->service->getKnowledgeForPrompt('demo.myshopify.com', ['greeting']));
        $this->assertSame('', $this->service->getKnowledgeForPrompt('demo.myshopify.com', []));
    }

    public function test_get_knowledge_for_prompt_picks_rows_by_intent_map(): void
    {
        StoreKnowledge::factory()->forShop('demo.myshopify.com')->policy()->create([
            'title' => 'Refunds',
            'summary' => 'Refunds available within 14 days.',
        ]);
        StoreKnowledge::factory()->forShop('demo.myshopify.com')->ofType(StoreKnowledge::TYPE_PAGE)->create([
            'title' => 'About',
            'summary' => 'We are a shop.',
        ]);
        StoreKnowledge::factory()->forShop('other.myshopify.com')->policy()->create([
            'title' => 'Other shop policy',
            'summary' => 'Should not appear.',
        ]);

        $block = $this->service->getKnowledgeForPrompt('demo.myshopify.com', ['refund_policy']);

        $this->assertStringContainsString('Refunds', $block);
        $this->assertStringNotContainsString('About', $block);
        $this->assertStringNotContainsString('Other shop policy', $block);
    }

    public function test_sync_all_chunks_a_long_page_into_multiple_summarise_jobs(): void
    {
        Queue::fake();

        $longBody = '<h2>Origins</h2><p>'.str_repeat('origin word ', 60).'</p>'
            .'<h2>Philosophy</h2><p>'.str_repeat('philosophy word ', 60).'</p>'
            .'<h2>Today</h2><p>'.str_repeat('today word ', 60).'</p>';

        $this->admin->mockResponse('admin/pages/list_pages', [
            'data' => [
                'pages' => [
                    'edges' => [[
                        'node' => [
                            'id' => 'gid://shopify/Page/1',
                            'title' => 'About Scott',
                            'handle' => 'about-scott',
                            'body' => $longBody,
                            'updatedAt' => '2026-01-01T00:00:00Z',
                        ],
                        'cursor' => 'cur1',
                    ]],
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'cur1'],
                ],
            ],
        ]);

        $this->service->syncAll('demo.myshopify.com');

        Queue::assertPushed(SummariseKnowledgeItemJob::class, 3);
        foreach ([0, 1, 2] as $index) {
            Queue::assertPushed(
                SummariseKnowledgeItemJob::class,
                fn (SummariseKnowledgeItemJob $job): bool => $job->handle === "about-scott#{$index}"
                    && $job->documentHandle === 'about-scott'
                    && $job->chunkIndex === $index
            );
        }
    }

    public function test_sync_all_does_not_chunk_a_short_page(): void
    {
        Queue::fake();

        $this->admin->mockResponse('admin/pages/list_pages', [
            'data' => [
                'pages' => [
                    'edges' => [[
                        'node' => [
                            'id' => 'gid://shopify/Page/1',
                            'title' => 'Contact',
                            'handle' => 'contact',
                            'body' => '<p>Reach us at hello@example.com.</p>',
                            'updatedAt' => '2026-01-01T00:00:00Z',
                        ],
                        'cursor' => 'cur1',
                    ]],
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'cur1'],
                ],
            ],
        ]);

        $this->service->syncAll('demo.myshopify.com');

        // document_handle is still populated (every row this code path
        // creates gets one) — only chunk_index reflects "never split".
        Queue::assertPushed(
            SummariseKnowledgeItemJob::class,
            fn (SummariseKnowledgeItemJob $job): bool => $job->handle === 'contact'
                && $job->documentHandle === 'contact'
                && $job->chunkIndex === null
        );
    }

    public function test_sync_all_reconciles_stale_chunks_when_a_document_shrinks(): void
    {
        Queue::fake();

        // Simulate a previous sync that produced 3 chunks.
        foreach ([0, 1, 2] as $index) {
            StoreKnowledge::factory()->forShop('demo.myshopify.com')->ofType(StoreKnowledge::TYPE_PAGE)->create([
                'handle' => "about-scott#{$index}",
                'document_handle' => 'about-scott',
                'chunk_index' => $index,
            ]);
        }

        // The page has since been edited down to something short.
        $this->admin->mockResponse('admin/pages/list_pages', [
            'data' => [
                'pages' => [
                    'edges' => [[
                        'node' => [
                            'id' => 'gid://shopify/Page/1',
                            'title' => 'About Scott',
                            'handle' => 'about-scott',
                            'body' => '<p>Scott founded the shop in 2020.</p>',
                            'updatedAt' => '2026-02-01T00:00:00Z',
                        ],
                        'cursor' => 'cur1',
                    ]],
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'cur1'],
                ],
            ],
        ]);

        $this->service->syncAll('demo.myshopify.com');

        $this->assertSame(
            0,
            StoreKnowledge::query()->where('document_handle', 'about-scott')->count(),
            'stale chunk rows from the previous, longer version of the page must be gone'
        );
        Queue::assertPushed(
            SummariseKnowledgeItemJob::class,
            fn (SummariseKnowledgeItemJob $job): bool => $job->handle === 'about-scott' && $job->chunkIndex === null
        );
    }

    public function test_sync_all_reconciles_a_legacy_single_row_when_a_document_grows_into_chunks(): void
    {
        Queue::fake();

        // A row created before chunking existed: no document_handle at all.
        StoreKnowledge::factory()->forShop('demo.myshopify.com')->ofType(StoreKnowledge::TYPE_PAGE)->create([
            'handle' => 'about-scott',
            'document_handle' => null,
            'chunk_index' => null,
        ]);

        $longBody = '<h2>Origins</h2><p>'.str_repeat('origin word ', 60).'</p>'
            .'<h2>Philosophy</h2><p>'.str_repeat('philosophy word ', 60).'</p>';

        $this->admin->mockResponse('admin/pages/list_pages', [
            'data' => [
                'pages' => [
                    'edges' => [[
                        'node' => [
                            'id' => 'gid://shopify/Page/1',
                            'title' => 'About Scott',
                            'handle' => 'about-scott',
                            'body' => $longBody,
                            'updatedAt' => '2026-02-01T00:00:00Z',
                        ],
                        'cursor' => 'cur1',
                    ]],
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'cur1'],
                ],
            ],
        ]);

        $this->service->syncAll('demo.myshopify.com');

        $this->assertSame(
            0,
            StoreKnowledge::query()->where('handle', 'about-scott')->count(),
            'the legacy unchunked row must be reconciled away once the document is chunked'
        );
        Queue::assertPushed(SummariseKnowledgeItemJob::class, 2);
    }

    public function test_embed_query_retries_once_then_succeeds_without_degrading(): void
    {
        config([
            'sales.knowledge.retrieval.enable_semantic' => true,
            'sales.knowledge.embedding.query_retry_attempts' => 1,
            'sales.knowledge.embedding.query_retry_delay_ms' => 0,
        ]);

        StoreKnowledge::factory()->forShop('demo.myshopify.com')->create([
            'title' => 'About Scott',
            'summary' => 'Scott founded the shop.',
            'embedding' => [1.0, 0.0],
        ]);

        // First attempt fails, retry succeeds.
        OpenAI::fake([
            new RuntimeException('transient failure'),
            CreateResponse::fake(['data' => [['embedding' => [1.0, 0.0]]]]),
        ]);

        $block = $this->service->getKnowledgeForPrompt('demo.myshopify.com', ['product_support'], 'tell me about scott');

        $this->assertStringContainsString('About Scott', $block);
        $this->assertFalse($this->service->wasLastRetrievalDegraded());
    }

    public function test_embed_query_degrades_and_hedges_after_exhausting_retries(): void
    {
        config([
            'sales.knowledge.retrieval.enable_semantic' => true,
            'sales.knowledge.embedding.query_retry_attempts' => 1,
            'sales.knowledge.embedding.query_retry_delay_ms' => 0,
        ]);

        StoreKnowledge::factory()->forShop('demo.myshopify.com')->create([
            'title' => 'About Scott',
            'summary' => 'Scott founded the shop.',
            'embedding' => [1.0, 0.0],
        ]);

        // Both the initial attempt and the retry fail.
        OpenAI::fake([
            new RuntimeException('transient failure'),
            new RuntimeException('still failing'),
        ]);

        $this->service->getKnowledgeForPrompt('demo.myshopify.com', ['product_support'], 'tell me about scott');

        $this->assertTrue($this->service->wasLastRetrievalDegraded());
    }

    public function test_was_last_retrieval_degraded_resets_on_next_call(): void
    {
        config([
            'sales.knowledge.retrieval.enable_semantic' => true,
            'sales.knowledge.embedding.query_retry_attempts' => 1,
            'sales.knowledge.embedding.query_retry_delay_ms' => 0,
        ]);

        StoreKnowledge::factory()->forShop('demo.myshopify.com')->create([
            'title' => 'About Scott',
            'summary' => 'Scott founded the shop.',
            'embedding' => [1.0, 0.0],
        ]);

        OpenAI::fake([
            new RuntimeException('fail 1'),
            new RuntimeException('fail 2'),
        ]);
        $this->service->getKnowledgeForPrompt('demo.myshopify.com', ['product_support'], 'first query');
        $this->assertTrue($this->service->wasLastRetrievalDegraded());

        // A second, distinct call that never touches embeddings (no query)
        // must not carry the previous call's degraded flag forward.
        StoreKnowledge::factory()->forShop('demo.myshopify.com')->policy()->create();
        config(['sales.knowledge.intent_content_map' => ['refund_policy' => ['policy']]]);
        $this->service->getKnowledgeForPrompt('demo.myshopify.com', ['refund_policy']);
        $this->assertFalse($this->service->wasLastRetrievalDegraded());
    }

    public function test_upsert_faq_creates_then_updates_same_handle(): void
    {
        $first = $this->service->upsertFaq('demo.myshopify.com', 'Shipping time?', 'Three to five days.');
        $second = $this->service->upsertFaq('demo.myshopify.com', 'Shipping time?', 'Two working days.');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('Two working days.', $second->raw_content);
        $this->assertSame(1, StoreKnowledge::query()->count());
    }

    public function test_upsert_faq_invalidates_prompt_cache(): void
    {
        StoreKnowledge::factory()->forShop('demo.myshopify.com')->faq()->create([
            'title' => 'Original FAQ',
            'handle' => 'original-faq',
            'summary' => 'Original summary',
        ]);

        // Prime cache for product_support which maps to page/blog (not faq).
        // We need to map an intent to faq to test invalidation correctly,
        // so override map for this test.
        config(['sales.knowledge.intent_content_map' => ['product_support' => ['faq']]]);
        $primed = $this->service->getKnowledgeForPrompt('demo.myshopify.com', ['product_support']);
        $this->assertStringContainsString('Original FAQ', $primed);

        $this->service->upsertFaq('demo.myshopify.com', 'New FAQ', 'New summary content.');

        $rebuilt = $this->service->getKnowledgeForPrompt('demo.myshopify.com', ['product_support']);
        $this->assertStringContainsString('New FAQ', $rebuilt);
    }
}
