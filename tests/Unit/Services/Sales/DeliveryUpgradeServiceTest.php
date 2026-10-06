<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sales;

use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\Services\AI\ChatbotConfigRepository;
use App\Services\Sales\DeliveryUpgradeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * DeliveryUpgradeService unit coverage (ADR 0022). Storefront client and
 * Chatbot config are mocked; no live Shopify.
 */
class DeliveryUpgradeServiceTest extends TestCase
{
    private const SHOP = 'demo.myshopify.com';

    /** @var MockInterface&StorefrontApiClientInterface */
    private $storefront;

    /** @var MockInterface&ChatbotConfigRepository */
    private $config;

    private DeliveryUpgradeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->storefront = Mockery::mock(StorefrontApiClientInterface::class);
        $this->config = Mockery::mock(ChatbotConfigRepository::class);
        $this->config->shouldReceive('deliveryUpgradeEnabled')->andReturn(true)->byDefault();
        $this->config->shouldReceive('deliveryUpgradeEligibleCollectionHandles')
            ->andReturn(['email-readings', 'readings'])
            ->byDefault();

        $this->service = new DeliveryUpgradeService($this->storefront, $this->config);
    }

    // -- getUpgradeProducts ----------------------------------------------------

    public function test_returns_only_tagged_available_products(): void
    {
        $this->storefront->shouldReceive('query')
            ->once()
            ->with('storefront/products/get_delivery_upgrade_products', Mockery::on(
                fn (array $vars): bool => $vars['query'] === 'tag:"delivery-upgrade"'
            ))
            ->andReturn(['data' => ['products' => ['edges' => [
                ['node' => $this->productNode('1', 'Add SAME DAY Guarantee', ['delivery-upgrade'])],
                ['node' => $this->productNode('2', 'Stale search hit', ['something-else'])],
                ['node' => $this->productNode('3', 'Sold Out Upgrade', ['Delivery-Upgrade'], available: false)],
            ]]]]);

        $products = $this->service->getUpgradeProducts(self::SHOP, 'GBP');

        $this->assertCount(1, $products);
        $this->assertSame('Add SAME DAY Guarantee', $products[0]->title);
        $this->assertSame('add-same-day-guarantee', $products[0]->handle);
    }

    public function test_returns_empty_when_no_tagged_products_exist(): void
    {
        $this->storefront->shouldReceive('query')
            ->once()
            ->andReturn(['data' => ['products' => ['edges' => []]]]);

        $this->assertSame([], $this->service->getUpgradeProducts(self::SHOP));
    }

    public function test_upgrade_products_are_cached_between_calls(): void
    {
        $this->storefront->shouldReceive('query')
            ->once()
            ->andReturn(['data' => ['products' => ['edges' => [
                ['node' => $this->productNode('1', 'Add SAME DAY Guarantee', ['delivery-upgrade'])],
            ]]]]);

        $first = $this->service->getUpgradeProducts(self::SHOP, 'GBP');
        $second = $this->service->getUpgradeProducts(self::SHOP, 'GBP');

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
    }

    public function test_empty_upgrade_result_is_only_cached_briefly_so_a_new_tag_appears_quickly(): void
    {
        $this->storefront->shouldReceive('query')
            ->twice()
            ->with('storefront/products/get_delivery_upgrade_products', Mockery::any())
            ->andReturn(
                ['data' => ['products' => ['edges' => []]]],
                ['data' => ['products' => ['edges' => [
                    ['node' => $this->productNode('1', 'Add SAME DAY Guarantee', ['delivery-upgrade'])],
                ]]]],
            );

        $this->assertSame([], $this->service->getUpgradeProducts(self::SHOP, 'GBP'));

        // Still inside the short empty-result window: served from cache.
        $this->assertSame([], $this->service->getUpgradeProducts(self::SHOP, 'GBP'));

        $this->travel(2)->minutes();

        $products = $this->service->getUpgradeProducts(self::SHOP, 'GBP');
        $this->assertCount(1, $products);
        $this->assertSame('Add SAME DAY Guarantee', $products[0]->title);
    }

    public function test_upgrade_lookup_returns_empty_when_kill_switch_is_off(): void
    {
        $this->config->shouldReceive('deliveryUpgradeEnabled')->andReturn(false);
        $this->storefront->shouldNotReceive('query');

        $this->assertSame([], $this->service->getUpgradeProducts(self::SHOP));
    }

    public function test_upgrade_lookup_returns_empty_for_blank_shop(): void
    {
        $this->storefront->shouldNotReceive('query');

        $this->assertSame([], $this->service->getUpgradeProducts(''));
    }

    public function test_upgrade_lookup_swallows_storefront_failure_and_logs_warning(): void
    {
        // CurrencyCountryMapService may emit its own Log::warning while
        // resolving the country; only the service's ai-channel warning is asserted.
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('channel')->with('ai')->andReturnSelf();
        Log::shouldReceive('log')->once()->with('warning', Mockery::any(), Mockery::any());
        $this->storefront->shouldReceive('query')->once()->andThrow(new \RuntimeException('boom'));

        $this->assertSame([], $this->service->getUpgradeProducts(self::SHOP));
    }

    // -- cartHasEligibleReading --------------------------------------------------

    public function test_cart_with_email_readings_product_is_eligible(): void
    {
        $this->mockFacts([
            'gid://shopify/Product/10' => ['tags' => ['love'], 'collections' => ['email-readings']],
        ]);

        $this->assertTrue($this->service->cartHasEligibleReading(
            [['product_id' => '10', 'quantity' => 1]],
            self::SHOP,
        ));
    }

    public function test_cart_with_readings_collection_product_is_eligible(): void
    {
        $this->mockFacts([
            'gid://shopify/Product/11' => ['tags' => [], 'collections' => ['readings']],
        ]);

        $this->assertTrue($this->service->cartHasEligibleReading(
            [['id' => 'gid://shopify/Product/11']],
            self::SHOP,
        ));
    }

    public function test_cart_with_only_ineligible_products_is_not_eligible(): void
    {
        $this->mockFacts([
            'gid://shopify/Product/12' => ['tags' => [], 'collections' => ['bracelets', 'necklaces']],
        ]);

        $this->assertFalse($this->service->cartHasEligibleReading(
            [['product_id' => '12']],
            self::SHOP,
        ));
    }

    public function test_upgrade_tagged_product_never_counts_as_an_eligible_reading(): void
    {
        $this->mockFacts([
            'gid://shopify/Product/13' => ['tags' => ['delivery-upgrade'], 'collections' => ['email-readings']],
        ]);

        $this->assertFalse($this->service->cartHasEligibleReading(
            [['product_id' => '13']],
            self::SHOP,
        ));
    }

    public function test_one_eligible_reading_among_other_products_is_enough(): void
    {
        $this->mockFacts([
            'gid://shopify/Product/14' => ['tags' => [], 'collections' => ['bracelets']],
            'gid://shopify/Product/15' => ['tags' => [], 'collections' => ['email-readings']],
        ]);

        $this->assertTrue($this->service->cartHasEligibleReading(
            [['product_id' => '14'], ['product_id' => '15']],
            self::SHOP,
        ));
    }

    public function test_changing_eligible_collections_config_changes_the_result_without_new_lookups(): void
    {
        $this->storefront->shouldReceive('query')
            ->once()
            ->with('storefront/products/get_products_collection_handles', Mockery::any())
            ->andReturn($this->factsResponse([
                'gid://shopify/Product/16' => ['tags' => [], 'collections' => ['meditations']],
            ]));

        $cart = [['product_id' => '16']];

        $this->assertFalse($this->service->cartHasEligibleReading($cart, self::SHOP));

        $this->config->shouldReceive('deliveryUpgradeEligibleCollectionHandles')
            ->andReturn(['email-readings', 'readings', 'meditations']);

        $this->assertTrue($this->service->cartHasEligibleReading($cart, self::SHOP));
    }

    public function test_cart_facts_are_cached_between_calls(): void
    {
        $this->mockFacts([
            'gid://shopify/Product/17' => ['tags' => [], 'collections' => ['email-readings']],
        ]);

        $cart = [['product_id' => '17']];

        $this->assertTrue($this->service->cartHasEligibleReading($cart, self::SHOP));
        $this->assertTrue($this->service->cartHasEligibleReading($cart, self::SHOP));
    }

    public function test_eligibility_is_false_when_kill_switch_is_off(): void
    {
        $this->config->shouldReceive('deliveryUpgradeEnabled')->andReturn(false);
        $this->storefront->shouldNotReceive('query');

        $this->assertFalse($this->service->cartHasEligibleReading([['product_id' => '18']], self::SHOP));
    }

    public function test_eligibility_is_false_for_empty_cart_or_blank_shop(): void
    {
        $this->storefront->shouldNotReceive('query');

        $this->assertFalse($this->service->cartHasEligibleReading([], self::SHOP));
        $this->assertFalse($this->service->cartHasEligibleReading([['product_id' => '19']], ''));
    }

    public function test_eligibility_ignores_cart_items_without_a_usable_product_id(): void
    {
        $this->storefront->shouldNotReceive('query');

        $this->assertFalse($this->service->cartHasEligibleReading(
            [['quantity' => 1], ['product_id' => 'not-a-number']],
            self::SHOP,
        ));
    }

    public function test_eligibility_swallows_storefront_failure_and_logs_warning(): void
    {
        Log::shouldReceive('channel')->with('ai')->andReturnSelf();
        Log::shouldReceive('log')->once()->with('warning', Mockery::any(), Mockery::any());
        $this->storefront->shouldReceive('query')->once()->andThrow(new \RuntimeException('boom'));

        $this->assertFalse($this->service->cartHasEligibleReading([['product_id' => '20']], self::SHOP));
    }

    // -- helpers -----------------------------------------------------------------

    /**
     * @param  array<string, array{tags: list<string>, collections: list<string>}>  $facts
     */
    private function mockFacts(array $facts): void
    {
        $this->storefront->shouldReceive('query')
            ->once()
            ->with('storefront/products/get_products_collection_handles', Mockery::any())
            ->andReturn($this->factsResponse($facts));
    }

    /**
     * @param  array<string, array{tags: list<string>, collections: list<string>}>  $facts
     * @return array<string, mixed>
     */
    private function factsResponse(array $facts): array
    {
        $nodes = [];
        foreach ($facts as $gid => $fact) {
            $nodes[] = [
                'id' => $gid,
                'tags' => $fact['tags'],
                'collections' => ['edges' => array_map(
                    static fn (string $handle): array => ['node' => ['handle' => $handle]],
                    $fact['collections'],
                )],
            ];
        }

        return ['data' => ['nodes' => $nodes]];
    }

    /**
     * @param  list<string>  $tags
     * @return array<string, mixed>
     */
    private function productNode(string $id, string $title, array $tags, bool $available = true): array
    {
        return [
            'id' => "gid://shopify/Product/{$id}",
            'title' => $title,
            'handle' => strtolower(str_replace(' ', '-', $title)),
            'tags' => $tags,
            'availableForSale' => $available,
            'priceRange' => ['minVariantPrice' => ['amount' => '8.99', 'currencyCode' => 'GBP']],
            'variants' => ['edges' => [['node' => [
                'id' => "gid://shopify/ProductVariant/{$id}",
                'availableForSale' => $available,
                'price' => ['amount' => '8.99', 'currencyCode' => 'GBP'],
            ]]]],
        ];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
