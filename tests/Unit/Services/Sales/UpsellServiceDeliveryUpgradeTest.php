<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sales;

use App\Contracts\Services\Sales\DeliveryUpgradeServiceInterface;
use App\DTOs\Sales\UpsellSuggestionDTO;
use App\Services\Sales\UpsellService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use Tests\Mocks\MockShopifyClient;
use Tests\TestCase;

/**
 * UpsellService x Delivery Upgrade Products (ADR 0022): upgrades lead the
 * list, count towards the cap, and never disturb the regular recommendations
 * when absent or failing.
 */
class UpsellServiceDeliveryUpgradeTest extends TestCase
{
    private const SHOP = 'demo.myshopify.com';

    private MockShopifyClient $shopify;

    /** @var MockInterface&DeliveryUpgradeServiceInterface */
    private $deliveryUpgrade;

    private UpsellService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['sales.upsell.max_results' => 3]);

        $this->shopify = new MockShopifyClient;
        $this->deliveryUpgrade = Mockery::mock(DeliveryUpgradeServiceInterface::class);
        $this->service = new UpsellService($this->shopify, $this->deliveryUpgrade);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_delivery_upgrade_is_first_and_counts_within_the_cap(): void
    {
        $this->eligibleCartWithUpgrades([$this->upgrade('900', 'Add SAME DAY Guarantee')]);
        $this->mockRecommendations([
            $this->node('101', 'Rec A'),
            $this->node('102', 'Rec B'),
            $this->node('103', 'Rec C'),
        ]);

        $out = $this->service->getUpsells([['product_id' => '10']], self::SHOP, 'GBP');

        $this->assertCount(3, $out);
        $this->assertSame('Add SAME DAY Guarantee', $out[0]->title);
        $this->assertSame(UpsellSuggestionDTO::TYPE_DELIVERY_UPGRADE, $out[0]->type);
        $this->assertSame('Rec A', $out[1]->title);
        $this->assertSame(UpsellSuggestionDTO::TYPE_RECOMMENDATION, $out[1]->type);
        $this->assertSame('Rec B', $out[2]->title);
    }

    public function test_cart_without_eligible_reading_gets_only_regular_recommendations(): void
    {
        $this->deliveryUpgrade->shouldReceive('cartHasEligibleReading')->once()->andReturn(false);
        $this->deliveryUpgrade->shouldNotReceive('getUpgradeProducts');
        $this->mockRecommendations([$this->node('101', 'Rec A'), $this->node('102', 'Rec B')]);

        $out = $this->service->getUpsells([['product_id' => '10']], self::SHOP, 'GBP');

        $this->assertCount(2, $out);
        foreach ($out as $suggestion) {
            $this->assertSame(UpsellSuggestionDTO::TYPE_RECOMMENDATION, $suggestion->type);
        }
    }

    public function test_upgrade_already_in_cart_is_not_recommended_again(): void
    {
        $this->eligibleCartWithUpgrades([$this->upgrade('900', 'Add SAME DAY Guarantee')]);
        $this->mockRecommendations([$this->node('101', 'Rec A')]);

        $out = $this->service->getUpsells(
            [['product_id' => '10'], ['product_id' => '900']],
            self::SHOP,
            'GBP',
        );

        $this->assertCount(1, $out);
        $this->assertSame('Rec A', $out[0]->title);
    }

    public function test_upgrade_in_cart_by_variant_id_is_not_recommended_again(): void
    {
        $this->eligibleCartWithUpgrades([$this->upgrade('900', 'Add SAME DAY Guarantee')]);
        $this->mockRecommendations([$this->node('101', 'Rec A')]);

        $out = $this->service->getUpsells(
            [['product_id' => '10'], ['variant_id' => 'gid://shopify/ProductVariant/900']],
            self::SHOP,
            'GBP',
        );

        $this->assertCount(1, $out);
        $this->assertSame('Rec A', $out[0]->title);
    }

    public function test_upgrade_is_not_duplicated_when_shopify_also_recommends_it(): void
    {
        $this->eligibleCartWithUpgrades([$this->upgrade('900', 'Add SAME DAY Guarantee')]);
        $this->mockRecommendations([$this->node('900', 'Add SAME DAY Guarantee'), $this->node('101', 'Rec A')]);

        $out = $this->service->getUpsells([['product_id' => '10']], self::SHOP, 'GBP');

        $titles = array_map(static fn (UpsellSuggestionDTO $s): string => $s->title, $out);
        $this->assertSame(['Add SAME DAY Guarantee', 'Rec A'], $titles);
    }

    public function test_multiple_upgrades_still_leave_a_slot_for_a_regular_recommendation(): void
    {
        $this->eligibleCartWithUpgrades([
            $this->upgrade('900', 'Same Day'),
            $this->upgrade('901', '48 Hours'),
            $this->upgrade('902', 'Third Upgrade'),
        ]);
        $this->mockRecommendations([$this->node('101', 'Rec A')]);

        $out = $this->service->getUpsells([['product_id' => '10']], self::SHOP, 'GBP');

        $this->assertCount(3, $out);
        $this->assertSame(
            [UpsellSuggestionDTO::TYPE_DELIVERY_UPGRADE, UpsellSuggestionDTO::TYPE_DELIVERY_UPGRADE, UpsellSuggestionDTO::TYPE_RECOMMENDATION],
            array_map(static fn (UpsellSuggestionDTO $s): string => $s->type, $out),
        );
    }

    public function test_cap_of_one_is_filled_by_the_upgrade_alone(): void
    {
        config(['sales.upsell.max_results' => 1]);
        $this->eligibleCartWithUpgrades([$this->upgrade('900', 'Same Day'), $this->upgrade('901', '48 Hours')]);
        // No recommendations mock: with the cap used up, Shopify must not be called.

        $out = $this->service->getUpsells([['product_id' => '10']], self::SHOP, 'GBP');

        $this->assertCount(1, $out);
        $this->assertSame('Same Day', $out[0]->title);
    }

    public function test_upgrade_failure_leaves_regular_recommendations_untouched(): void
    {
        $this->deliveryUpgrade->shouldReceive('cartHasEligibleReading')->once()->andThrow(new \RuntimeException('boom'));
        $this->mockRecommendations([$this->node('101', 'Rec A')]);

        $out = $this->service->getUpsells([['product_id' => '10']], self::SHOP, 'GBP');

        $this->assertCount(1, $out);
        $this->assertSame('Rec A', $out[0]->title);
    }

    public function test_without_a_delivery_upgrade_service_behaviour_is_unchanged(): void
    {
        $this->mockRecommendations([$this->node('101', 'Rec A')]);

        $out = (new UpsellService($this->shopify))->getUpsells([['product_id' => '10']], self::SHOP, 'GBP');

        $this->assertCount(1, $out);
        $this->assertSame(UpsellSuggestionDTO::TYPE_RECOMMENDATION, $out[0]->type);
    }

    public function test_delivery_upgrade_is_included_in_the_dto_array_and_prompt_payload_shape(): void
    {
        $dto = $this->upgrade('900', 'Add SAME DAY Guarantee');

        $this->assertSame('delivery_upgrade', $dto->toArray()['type']);
    }

    // -- helpers -----------------------------------------------------------------

    /**
     * @param  list<UpsellSuggestionDTO>  $upgrades
     */
    private function eligibleCartWithUpgrades(array $upgrades): void
    {
        $this->deliveryUpgrade->shouldReceive('cartHasEligibleReading')->once()->andReturn(true);
        $this->deliveryUpgrade->shouldReceive('getUpgradeProducts')->once()->andReturn($upgrades);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function mockRecommendations(array $nodes): void
    {
        $this->shopify->mockResponse('storefront/products/get_product_recommendations', [
            'data' => ['productRecommendations' => $nodes],
        ]);
    }

    private function upgrade(string $id, string $title): UpsellSuggestionDTO
    {
        return new UpsellSuggestionDTO(
            id: "gid://shopify/Product/{$id}",
            title: $title,
            handle: strtolower(str_replace(' ', '-', $title)),
            imageUrl: null,
            imageAlt: null,
            variantId: "gid://shopify/ProductVariant/{$id}",
            price: '8.99',
            currency: 'GBP',
            available: true,
            type: UpsellSuggestionDTO::TYPE_DELIVERY_UPGRADE,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function node(string $id, string $title): array
    {
        return [
            'id' => "gid://shopify/Product/{$id}",
            'title' => $title,
            'handle' => strtolower(str_replace(' ', '-', $title)),
            'availableForSale' => true,
            'priceRange' => ['minVariantPrice' => ['amount' => '5.00', 'currencyCode' => 'GBP']],
            'variants' => ['edges' => [['node' => [
                'id' => "gid://shopify/ProductVariant/{$id}",
                'availableForSale' => true,
                'price' => ['amount' => '5.00', 'currencyCode' => 'GBP'],
            ]]]],
        ];
    }
}
