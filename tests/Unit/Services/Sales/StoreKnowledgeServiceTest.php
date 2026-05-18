<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sales;

use App\Jobs\Sales\SummariseKnowledgeItemJob;
use App\Models\StoreKnowledge;
use App\Services\Sales\StoreKnowledgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
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
