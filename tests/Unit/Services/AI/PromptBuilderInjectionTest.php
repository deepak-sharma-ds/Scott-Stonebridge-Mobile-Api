<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Contracts\Services\AI\ConversationServiceInterface;
use App\Contracts\Services\Sales\StoreKnowledgeServiceInterface;
use App\Contracts\Services\Sales\UpsellServiceInterface;
use App\DTOs\Chat\CartContextDTO;
use App\DTOs\Chat\ChatContextDTO;
use App\DTOs\Chat\IntentDTO;
use App\DTOs\Sales\UpsellSuggestionDTO;
use App\Services\AI\PromptBuilderService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Phase C — PromptBuilderService injection methods (Step 6).
 *
 *   injectUpsellContext  — block content + visibility threshold rules
 *   injectStoreKnowledge — stubbed until Step 7/8 (empty string today)
 *   injectLocaleRule     — final LANGUAGE RULE block
 */
class PromptBuilderInjectionTest extends TestCase
{
    private PromptBuilderService $builder;

    /** @var MockInterface&UpsellServiceInterface */
    private $upsell;

    /** @var MockInterface&StoreKnowledgeServiceInterface */
    private $knowledge;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sales.upsell.default_free_shipping_threshold' => 50.00,
            'sales.upsell.free_ship_gap_visibility' => 0.20,
            'sales.locale.fallback' => 'en',
        ]);

        $conversations = Mockery::mock(ConversationServiceInterface::class);
        $this->upsell = Mockery::mock(UpsellServiceInterface::class);
        $this->knowledge = Mockery::mock(StoreKnowledgeServiceInterface::class);

        $this->builder = new PromptBuilderService($conversations, $this->upsell, $this->knowledge);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_inject_upsell_context_returns_empty_for_non_sales_intent(): void
    {
        $intent = new IntentDTO(IntentDTO::INTENT_PRODUCT_SUPPORT, 0.85, [], 'regex');
        $ctx = $this->context(cartTotal: '20.00');

        $this->assertSame('', $this->builder->injectUpsellContext($intent, $ctx, []));
    }

    public function test_inject_upsell_context_renders_products_and_threshold(): void
    {
        $this->upsell->shouldReceive('getFreeShippingGap')->andReturn(8.00);

        $intent = new IntentDTO(IntentDTO::INTENT_UPSELL_OPPORTUNITY, 0.8, [], 'regex');
        $ctx = $this->context(cartTotal: '42.00');
        $upsells = [
            new UpsellSuggestionDTO(
                id: 'gid://shopify/Product/1',
                title: 'Wireless Charger',
                handle: 'wireless-charger',
                imageUrl: null,
                imageAlt: null,
                variantId: 'v1',
                price: '19.99',
                currency: 'GBP',
                available: true,
            ),
        ];

        $block = $this->builder->injectUpsellContext($intent, $ctx, $upsells);

        $this->assertStringContainsString('UPSELL CONTEXT:', $block);
        $this->assertStringContainsString('Wireless Charger', $block);
        $this->assertStringContainsString('handle: wireless-charger', $block);
        $this->assertStringContainsString('Free shipping threshold: 50.00', $block);
        $this->assertStringContainsString('Gap to free shipping: 8.00', $block);
        // 8/50 = 0.16 <= 0.20 visibility -> mention.
        $this->assertStringContainsString('within 20% of free shipping', $block);
        $this->assertStringContainsString('Suggest at most 2 of the above products', $block);
    }

    public function test_inject_upsell_context_hides_gap_outside_visibility_window(): void
    {
        $this->upsell->shouldReceive('getFreeShippingGap')->andReturn(40.00);

        $intent = new IntentDTO(IntentDTO::INTENT_CART_HELP, 0.8, [], 'regex');
        $ctx = $this->context(cartTotal: '10.00');

        $block = $this->builder->injectUpsellContext($intent, $ctx, []);

        $this->assertStringContainsString('Gap to free shipping: 40.00', $block);
        $this->assertStringContainsString('Do NOT mention the free shipping gap', $block);
    }

    public function test_inject_upsell_context_returns_empty_when_no_products_no_threshold(): void
    {
        config(['sales.upsell.default_free_shipping_threshold' => 0]);

        $intent = new IntentDTO(IntentDTO::INTENT_UPSELL_OPPORTUNITY, 0.8, [], 'regex');
        $ctx = $this->context(cartTotal: '0');

        $this->assertSame('', $this->builder->injectUpsellContext($intent, $ctx, []));
    }

    public function test_inject_store_knowledge_returns_empty_when_service_yields_nothing(): void
    {
        $this->knowledge->shouldReceive('getKnowledgeForPrompt')
            ->with('demo.myshopify.com', [IntentDTO::INTENT_REFUND_POLICY])
            ->andReturn('');

        $intent = new IntentDTO(IntentDTO::INTENT_REFUND_POLICY, 0.85, [], 'regex');
        $ctx = $this->context(cartTotal: '0');

        $this->assertSame('', $this->builder->injectStoreKnowledge($intent, $ctx));
    }

    public function test_inject_store_knowledge_renders_block_with_directives(): void
    {
        $this->knowledge->shouldReceive('getKnowledgeForPrompt')
            ->with('demo.myshopify.com', [IntentDTO::INTENT_REFUND_POLICY])
            ->andReturn('- [policy] Refunds — Refunds within 14 days.');

        $intent = new IntentDTO(IntentDTO::INTENT_REFUND_POLICY, 0.85, [], 'regex');
        $ctx = $this->context(cartTotal: '0');

        $block = $this->builder->injectStoreKnowledge($intent, $ctx);

        $this->assertStringContainsString('STORE KNOWLEDGE:', $block);
        $this->assertStringContainsString('Refunds within 14 days.', $block);
        $this->assertStringContainsString('Do not answer policy or store questions from memory', $block);
    }

    public function test_inject_store_knowledge_returns_empty_for_blank_shop(): void
    {
        // Shop blank -> service is never called.
        $this->knowledge->shouldNotReceive('getKnowledgeForPrompt');

        config(['shopify.store_domain' => '']);

        $ctx = new ChatContextDTO(
            pageType: 'cart',
            product: null,
            cart: new CartContextDTO(id: null, itemCount: 0, totalPrice: null, currency: null, items: []),
            customer: null,
            recentlyViewed: [],
            shopDomain: null,
            currency: 'GBP',
            locale: 'en',
        );

        $intent = new IntentDTO(IntentDTO::INTENT_REFUND_POLICY, 0.85, [], 'regex');

        $this->assertSame('', $this->builder->injectStoreKnowledge($intent, $ctx));
    }

    public function test_inject_locale_rule_emits_directive_with_provided_locale(): void
    {
        $block = $this->builder->injectLocaleRule('fr');

        $this->assertStringContainsString('LANGUAGE RULE:', $block);
        $this->assertStringContainsString('Respond exclusively in: fr', $block);
    }

    public function test_inject_locale_rule_falls_back_when_locale_blank(): void
    {
        $block = $this->builder->injectLocaleRule(null);

        $this->assertStringContainsString('Respond exclusively in: en', $block);
    }

    private function context(string $cartTotal): ChatContextDTO
    {
        return new ChatContextDTO(
            pageType: 'cart',
            product: null,
            cart: new CartContextDTO(id: 'c', itemCount: 1, totalPrice: $cartTotal, currency: 'GBP', items: []),
            customer: null,
            recentlyViewed: [],
            shopDomain: 'demo.myshopify.com',
            currency: 'GBP',
            locale: 'en',
        );
    }
}
