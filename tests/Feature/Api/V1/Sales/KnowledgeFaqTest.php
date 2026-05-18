<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Sales;

use App\Http\Middleware\ShopifyAuthMiddleware;
use App\Models\StoreKnowledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Phase D — POST /api/v1/ai/knowledge/faq coverage.
 *
 * Endpoint is merchant/internal — guarded by shopify.auth in routes.
 * We bypass the middleware in tests because Shopify auth is exercised
 * elsewhere and isn't the focus here.
 */
class KnowledgeFaqTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ThrottleRequests::class, ShopifyAuthMiddleware::class]);
        Cache::flush();
    }

    public function test_upsert_creates_new_faq_row(): void
    {
        $response = $this->postJson('/api/v1/ai/knowledge/faq', [
            'shop_domain' => 'demo.myshopify.com',
            'question' => 'Do you offer gift wrapping?',
            'answer' => 'Yes, gift wrapping is available at checkout for £2.',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.content_type', StoreKnowledge::TYPE_FAQ);
        $response->assertJsonPath('data.title', 'Do you offer gift wrapping?');
        $response->assertJsonPath('data.handle', 'do-you-offer-gift-wrapping');

        $this->assertDatabaseHas('store_knowledge', [
            'shop_domain' => 'demo.myshopify.com',
            'content_type' => StoreKnowledge::TYPE_FAQ,
            'title' => 'Do you offer gift wrapping?',
        ]);
    }

    public function test_upsert_updates_existing_faq_with_same_handle(): void
    {
        // First insert
        $this->postJson('/api/v1/ai/knowledge/faq', [
            'shop_domain' => 'demo.myshopify.com',
            'question' => 'Shipping time?',
            'answer' => 'Three to five working days.',
        ])->assertStatus(201);

        $this->assertSame(1, StoreKnowledge::query()->count());

        // Update with same question
        $response = $this->postJson('/api/v1/ai/knowledge/faq', [
            'shop_domain' => 'demo.myshopify.com',
            'question' => 'Shipping time?',
            'answer' => 'Two working days from order placement.',
        ]);

        $response->assertStatus(201);
        $this->assertSame(1, StoreKnowledge::query()->count());

        $this->assertDatabaseHas('store_knowledge', [
            'shop_domain' => 'demo.myshopify.com',
            'title' => 'Shipping time?',
            'raw_content' => 'Two working days from order placement.',
        ]);
    }

    public function test_upsert_rejects_short_question(): void
    {
        $response = $this->postJson('/api/v1/ai/knowledge/faq', [
            'shop_domain' => 'demo.myshopify.com',
            'question' => 'X',
            'answer' => 'Some long enough answer here.',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_upsert_rejects_missing_answer(): void
    {
        $response = $this->postJson('/api/v1/ai/knowledge/faq', [
            'shop_domain' => 'demo.myshopify.com',
            'question' => 'How does it work?',
        ]);

        $response->assertStatus(422);
    }
}
