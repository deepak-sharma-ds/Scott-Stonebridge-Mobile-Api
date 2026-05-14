<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\DTOs\Chat\ChatContextDTO;
use App\DTOs\Chat\IntentDTO;
use App\Services\AI\IntentDetectionService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class IntentDetectionServiceTest extends TestCase
{
    private IntentDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->service = new IntentDetectionService;
    }

    public function test_recommendation_keyword_is_classified_via_regex(): void
    {
        $context = $this->context('home');
        $result = $this->service->detect('Recommend me some gaming headphones', $context);

        $this->assertSame(IntentDTO::INTENT_RECOMMENDATION, $result->name);
        $this->assertSame('regex', $result->detectedBy);
        $this->assertGreaterThanOrEqual(0.65, $result->confidence);
    }

    public function test_order_tracking_phrase_is_classified_via_regex(): void
    {
        $result = $this->service->detect('where is my order #1234?', $this->context('account'));

        $this->assertSame(IntentDTO::INTENT_ORDER_TRACKING, $result->name);
    }

    public function test_refund_keyword_routes_to_refund_policy_intent(): void
    {
        $result = $this->service->detect('Do you offer a refund on opened items?', $this->context('product'));

        $this->assertSame(IntentDTO::INTENT_REFUND_POLICY, $result->name);
    }

    public function test_product_page_default_intent_when_no_keyword(): void
    {
        // Lower threshold so the fast-path result is accepted without
        // dispatching the OpenAI classifier fallback in unit tests.
        config()->set('chatbot.intent.confidence_threshold', 0.5);
        $result = $this->service->detect('Hello there friend', $this->context('product'));

        $this->assertSame(IntentDTO::INTENT_GREETING, $result->name);
    }

    private function context(string $pageType): ChatContextDTO
    {
        return ChatContextDTO::fromArray([
            'page_type' => $pageType,
            'shop_domain' => 'demo.myshopify.com',
            'currency' => 'GBP',
            'locale' => 'en',
        ]);
    }
}
