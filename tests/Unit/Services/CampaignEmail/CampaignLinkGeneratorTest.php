<?php

namespace Tests\Unit\Services\CampaignEmail;

use App\Models\CampaignProduct;
use App\Models\MarketingCampaign;
use App\Services\CampaignEmail\CampaignLinkGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignLinkGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private CampaignLinkGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('shopify.store_domain', 'test-shop.myshopify.com');

        $this->generator = new CampaignLinkGenerator;
    }

    public function test_generates_a_valid_cart_permalink_carrying_the_campaign_key(): void
    {
        $campaign = MarketingCampaign::create([
            'campaign_key' => 'spring-sale',
            'name' => 'Spring Sale',
            'status' => MarketingCampaign::STATUS_DRAFT,
        ]);
        $campaignProduct = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
            'shopify_variant_id' => 5001,
        ]);

        $link = $this->generator->generate($campaignProduct);

        $this->assertStringStartsWith('https://test-shop.myshopify.com/cart/5001:1?properties=', $link);

        $encoded = substr($link, strpos($link, 'properties=') + strlen('properties='));
        $decoded = json_decode(base64_decode(rawurldecode($encoded)), true);

        $this->assertSame(['_campaign_key' => 'spring-sale'], $decoded);
    }

    public function test_uses_the_given_quantity(): void
    {
        $campaign = MarketingCampaign::create([
            'campaign_key' => 'spring-sale',
            'name' => 'Spring Sale',
            'status' => MarketingCampaign::STATUS_DRAFT,
        ]);
        $campaignProduct = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
            'shopify_variant_id' => 5001,
        ]);

        $link = $this->generator->generate($campaignProduct, 2);

        $this->assertStringStartsWith('https://test-shop.myshopify.com/cart/5001:2?properties=', $link);
    }

    public function test_returns_null_when_no_variant_id_is_set(): void
    {
        $campaign = MarketingCampaign::create([
            'campaign_key' => 'spring-sale',
            'name' => 'Spring Sale',
            'status' => MarketingCampaign::STATUS_DRAFT,
        ]);
        $campaignProduct = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
        ]);

        $this->assertNull($this->generator->generate($campaignProduct));
    }
}
