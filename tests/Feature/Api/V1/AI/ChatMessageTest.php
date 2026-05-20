<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\AI;

use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\Mocks\MockShopifyClient;
use Tests\TestCase;

/**
 * End-to-end test of the non-streamed /message endpoint with OpenAI + Shopify
 * Storefront fully mocked.
 */
class ChatMessageTest extends TestCase
{
    use RefreshDatabase;

    private MockShopifyClient $shopify;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->shopify = new MockShopifyClient;
        $this->app->instance(StorefrontApiClientInterface::class, $this->shopify);
    }

    public function test_message_endpoint_returns_ai_reply_and_persists_messages(): void
    {
        $conversation = AiConversation::factory()->create([
            'shop_domain' => 'demo.myshopify.com',
            'page_type' => 'product',
        ]);

        // Product detail mock (used by ShopifyContextService for product_support intent)
        $this->shopify->mockResponse('storefront/products/get_product_details', [
            'data' => [
                'productByHandle' => [
                    'id' => 'gid://shopify/Product/1',
                    'title' => 'Demo Headphones',
                    'handle' => 'demo-headphones',
                    'vendor' => 'Acme',
                    'productType' => 'Audio',
                    'tags' => ['music'],
                    'description' => 'Great sound',
                    'options' => [],
                    'variants' => [
                        'edges' => [[
                            'node' => [
                                'price' => ['amount' => '49.99', 'currencyCode' => 'GBP'],
                                'availableForSale' => true,
                            ],
                        ]],
                    ],
                ],
            ],
        ]);

        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'These Demo Headphones are waterproof up to IPX5.'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 120,
                    'completion_tokens' => 18,
                    'total_tokens' => 138,
                ],
            ]),
        ]);

        $response = $this->postJson('/api/v1/ai/chat/message', [
            'session_id' => $conversation->session_id,
            'message' => 'Is this waterproof?',
            'context' => [
                'page_type' => 'product',
                'product' => [
                    'id' => 'gid://shopify/Product/1',
                    'handle' => 'demo-headphones',
                    'title' => 'Demo Headphones',
                ],
                'shop_domain' => 'demo.myshopify.com',
                'currency' => 'GBP',
                'locale' => 'en',
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.intent', 'product_support');
        $response->assertJsonPath('data.reply', 'These Demo Headphones are waterproof up to IPX5.');

        $this->assertDatabaseHas('ai_messages', [
            'conversation_id' => $conversation->id,
            'role' => AiMessage::ROLE_USER,
        ]);
        $this->assertDatabaseHas('ai_messages', [
            'conversation_id' => $conversation->id,
            'role' => AiMessage::ROLE_ASSISTANT,
            'prompt_tokens' => 120,
            'completion_tokens' => 18,
        ]);
    }

    public function test_message_endpoint_rejects_unknown_session(): void
    {
        $response = $this->postJson('/api/v1/ai/chat/message', [
            'session_id' => '00000000-0000-0000-0000-000000000000',
            'message' => 'Hi',
            'context' => ['page_type' => 'home'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_message_endpoint_rejects_oversized_message(): void
    {
        $conversation = AiConversation::factory()->create();
        $tooLong = str_repeat('a', (int) config('chatbot.message.max_length', 2000) + 1);

        $response = $this->postJson('/api/v1/ai/chat/message', [
            'session_id' => $conversation->session_id,
            'message' => $tooLong,
            'context' => ['page_type' => 'home'],
        ]);

        $response->assertStatus(422);
    }
}
