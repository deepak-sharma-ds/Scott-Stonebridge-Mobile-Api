<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sales;

use App\Models\TriggerRule;
use App\Services\Sales\ProactiveTriggerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Unit coverage for ProactiveTriggerService.
 * Uses Tests\TestCase (not PHPUnit\Framework\TestCase) because the service
 * touches the Eloquent layer and the Cache facade.
 */
class ProactiveTriggerServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_get_top_trigger_for_page_returns_null_for_empty_inputs(): void
    {
        $service = new ProactiveTriggerService;

        $this->assertNull($service->getTopTriggerForPage('', 'shop.myshopify.com'));
        $this->assertNull($service->getTopTriggerForPage('product', ''));
    }

    public function test_get_top_trigger_for_page_orders_by_priority(): void
    {
        TriggerRule::factory()->forShop('a.myshopify.com')->forPage('product')->priority(30)->create();
        $winner = TriggerRule::factory()->forShop('a.myshopify.com')->forPage('product')->priority(1)->create();
        TriggerRule::factory()->forShop('a.myshopify.com')->forPage('product')->priority(10)->create();

        $service = new ProactiveTriggerService;
        $top = $service->getTopTriggerForPage('product', 'a.myshopify.com');

        $this->assertNotNull($top);
        $this->assertSame($winner->id, $top->id);
    }

    public function test_get_top_trigger_for_page_falls_back_to_page_all(): void
    {
        $rule = TriggerRule::factory()
            ->forShop('b.myshopify.com')
            ->forPage(TriggerRule::PAGE_ALL)
            ->create();

        $service = new ProactiveTriggerService;
        $top = $service->getTopTriggerForPage('cart', 'b.myshopify.com');

        $this->assertNotNull($top);
        $this->assertSame($rule->id, $top->id);
    }

    public function test_get_top_trigger_for_page_excludes_inactive(): void
    {
        TriggerRule::factory()
            ->forShop('c.myshopify.com')
            ->forPage('cart')
            ->inactive()
            ->create();

        $service = new ProactiveTriggerService;
        $this->assertNull($service->getTopTriggerForPage('cart', 'c.myshopify.com'));
    }

    public function test_build_proactive_message_interpolates_flat_and_nested_context(): void
    {
        $rule = TriggerRule::factory()->make([
            'message_template' => 'Hi {customer_name}, your {product_title} is waiting in your cart ({cart_total}).',
        ]);

        $service = new ProactiveTriggerService;
        $message = $service->buildProactiveMessage($rule, [
            'customer_name' => 'Sam',
            'product' => ['title' => 'Stonebridge Reading'],
            'cart' => ['total' => '£49.99'],
        ]);

        $this->assertSame(
            'Hi Sam, your Stonebridge Reading is waiting in your cart (£49.99).',
            $message,
        );
    }

    public function test_build_proactive_message_leaves_unknown_placeholders_intact(): void
    {
        $rule = TriggerRule::factory()->make([
            'message_template' => 'Hi {unknown_token}',
        ]);

        $service = new ProactiveTriggerService;
        $this->assertSame('Hi {unknown_token}', $service->buildProactiveMessage($rule, []));
    }

    public function test_should_fire_returns_false_for_empty_session(): void
    {
        $rule = TriggerRule::factory()->create();
        $service = new ProactiveTriggerService;

        $this->assertFalse($service->shouldFire('', $rule));
    }

    public function test_mark_fired_then_should_fire_returns_false(): void
    {
        $rule = TriggerRule::factory()->create();
        $service = new ProactiveTriggerService;

        $this->assertTrue($service->shouldFire('sess', $rule));
        $service->markFired('sess', (int) $rule->id);
        $this->assertFalse($service->shouldFire('sess', $rule));
    }

    public function test_mark_fired_is_noop_on_empty_session_or_zero_id(): void
    {
        $rule = TriggerRule::factory()->create();
        $service = new ProactiveTriggerService;

        $service->markFired('', (int) $rule->id);
        $service->markFired('sess', 0);

        $this->assertTrue($service->shouldFire('sess', $rule));
    }
}
