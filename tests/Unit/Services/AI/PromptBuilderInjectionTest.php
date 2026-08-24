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
use App\Models\AiConversation;
use App\Services\AI\PromptBuilderService;
use Illuminate\Support\Facades\View;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Phase C — PromptBuilderService injection methods (Step 6).
 *
 *   injectUpsellContext  — complementary-product block content
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

    public function test_inject_upsell_context_renders_products(): void
    {
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
        $this->assertStringContainsString('Suggest at most 2 of the above products', $block);
        // No free-shipping / threshold language must leak into the block.
        $this->assertStringNotContainsString('free shipping', strtolower($block));
    }

    public function test_inject_upsell_context_returns_empty_when_no_products(): void
    {
        $intent = new IntentDTO(IntentDTO::INTENT_UPSELL_OPPORTUNITY, 0.8, [], 'regex');
        $ctx = $this->context(cartTotal: '0');

        $this->assertSame('', $this->builder->injectUpsellContext($intent, $ctx, []));
    }

    public function test_inject_store_knowledge_returns_empty_when_service_yields_nothing(): void
    {
        $this->knowledge->shouldReceive('getKnowledgeForPrompt')
            ->with('demo.myshopify.com', [IntentDTO::INTENT_REFUND_POLICY])
            ->andReturn('');
        $this->knowledge->shouldReceive('wasLastRetrievalDegraded')->andReturn(false);

        $intent = new IntentDTO(IntentDTO::INTENT_REFUND_POLICY, 0.85, [], 'regex');
        $ctx = $this->context(cartTotal: '0');

        $this->assertSame('', $this->builder->injectStoreKnowledge($intent, $ctx));
    }

    public function test_inject_store_knowledge_renders_block_with_directives(): void
    {
        $this->knowledge->shouldReceive('getKnowledgeForPrompt')
            ->with('demo.myshopify.com', [IntentDTO::INTENT_REFUND_POLICY])
            ->andReturn('- [policy] Refunds — Refunds within 14 days.');
        $this->knowledge->shouldReceive('wasLastRetrievalDegraded')->andReturn(false);

        $intent = new IntentDTO(IntentDTO::INTENT_REFUND_POLICY, 0.85, [], 'regex');
        $ctx = $this->context(cartTotal: '0');

        $block = $this->builder->injectStoreKnowledge($intent, $ctx);

        $this->assertStringContainsString('STORE KNOWLEDGE:', $block);
        $this->assertStringContainsString('Refunds within 14 days.', $block);
        $this->assertStringContainsString('Do not answer policy or store questions from memory', $block);
    }

    public function test_inject_store_knowledge_appends_hedge_note_when_semantic_search_degraded(): void
    {
        $this->knowledge->shouldReceive('getKnowledgeForPrompt')
            ->with('demo.myshopify.com', [IntentDTO::INTENT_REFUND_POLICY], 'what is scott about')
            ->andReturn('');
        $this->knowledge->shouldReceive('wasLastRetrievalDegraded')->andReturn(true);

        $intent = new IntentDTO(IntentDTO::INTENT_REFUND_POLICY, 0.85, [], 'regex');
        $ctx = $this->context(cartTotal: '0');

        $block = $this->builder->injectStoreKnowledge($intent, $ctx, 'what is scott about');

        // Even with zero rows, a degraded search still renders the block —
        // this is the actual fix: previously an embedding failure produced
        // an empty block indistinguishable from "genuinely no knowledge".
        $this->assertStringContainsString('STORE KNOWLEDGE:', $block);
        $this->assertStringContainsString('Semantic search was unavailable this turn', $block);
        $this->assertStringContainsString('do not state that no information exists', $block);
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

    public function test_system_prompt_has_persona_and_adaptive_output_style(): void
    {
        $rendered = View::make('ai.prompts.system', [
            'shop' => 'demo.myshopify.com',
            'intent' => IntentDTO::INTENT_UNKNOWN,
            'page_type' => 'home',
            'currency' => 'GBP',
            'locale' => 'en',
            'resolved_context' => [],
            'products' => [],
            'upsell_block' => '',
            'knowledge_block' => '',
            'locale_block' => 'LANGUAGE RULE:',
        ])->render();

        // New persona + adaptive output guidance are present.
        $this->assertStringContainsString('PERSONA', $rendered);
        $this->assertStringContainsString('OUTPUT STYLE', $rendered);

        // Old rigid caps are gone.
        $this->assertStringNotContainsString('3 short sentences', $rendered);
        $this->assertStringNotContainsString('1–3 short paragraphs', $rendered);
    }

    public function test_build_drops_lowest_ranked_knowledge_rows_to_fit_budget_without_touching_other_blocks(): void
    {
        // Base template (persona/rules/tool-usage + locale + customer
        // blocks, no knowledge) is ~1742 tokens; +1 row ~1877, +2 rows
        // ~1949. Budget of 1900 keeps the highest-ranked row but forces the
        // other two to drop, without ever falling below the no-knowledge
        // baseline (which would trigger the blind-fallback path). Recompute
        // these numbers (see the ChatbotConfigRepository/PromptBuilder test
        // comment history) whenever system.blade.php's TOOL USAGE section
        // changes length.
        config(['sales.prompt_guard.system_prompt_max_tokens' => 1860]);

        // Ranked highest-relevance first, per StoreKnowledgeService's
        // contract — the fix must drop from the END of this list.
        $rankedRows = [
            '- [page] About Scott — '.str_repeat('Highest ranked detail. ', 12),
            '- [page] Scott FAQ — '.str_repeat('Second ranked detail. ', 12),
            '- [policy] Shipping — '.str_repeat('Lowest ranked detail. ', 12),
        ];

        $conversations = Mockery::mock(ConversationServiceInterface::class);
        $conversations->shouldReceive('historyTailAsMessages')->andReturn([]);

        $this->knowledge->shouldReceive('getKnowledgeForPrompt')
            ->with('demo.myshopify.com', [IntentDTO::INTENT_PRODUCT_SUPPORT], 'tell me about scott')
            ->andReturn(implode("\n", $rankedRows));
        $this->knowledge->shouldReceive('wasLastRetrievalDegraded')->andReturn(false);

        $builder = new PromptBuilderService($conversations, $this->upsell, $this->knowledge);

        $conversation = AiConversation::factory()->make();
        $intent = new IntentDTO(IntentDTO::INTENT_PRODUCT_SUPPORT, 0.85, [], 'regex');
        $ctx = $this->context(cartTotal: '0');

        $messages = $builder->build($conversation, $ctx, $intent, 'tell me about scott', customerSummary: [
            'order_count' => 1,
            'recent_orders' => [['number' => '1001', 'total' => '20.00', 'currency' => 'GBP', 'date' => '2026-01-01']],
        ]);

        $systemBody = $messages[0]['content'];

        // Highest-ranked row survives; lower-ranked rows were dropped from
        // the tail first, one at a time, to reach the budget.
        $this->assertStringContainsString('Highest ranked detail.', $systemBody);
        $this->assertStringNotContainsString('Second ranked detail.', $systemBody);
        $this->assertStringNotContainsString('Lowest ranked detail.', $systemBody);

        // Blocks after STORE KNOWLEDGE in the template are never truncated —
        // this is the actual fix: previously a flat mb_substr cut could
        // slice these off entirely.
        $this->assertStringContainsString('CUSTOMER CONTEXT:', $systemBody);
        $this->assertStringContainsString('Order #1001', $systemBody);
        $this->assertStringContainsString('LANGUAGE RULE:', $systemBody);
        $this->assertStringNotContainsString('[CONTEXT TRUNCATED', $systemBody, 'should not need the last-resort blind marker while knowledge rows remain droppable');
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
