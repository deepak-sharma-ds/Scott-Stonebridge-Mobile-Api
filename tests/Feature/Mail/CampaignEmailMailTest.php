<?php

namespace Tests\Feature\Mail;

use App\Mail\CampaignEmailMail;
use App\Models\CampaignDelivery;
use App\Models\CampaignProduct;
use App\Models\CampaignProductResponse;
use App\Models\MarketingCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignEmailMailTest extends TestCase
{
    use RefreshDatabase;

    private function delivery(array $campaignProductOverrides = []): CampaignDelivery
    {
        $campaign = MarketingCampaign::create([
            'campaign_key' => 'spring-sale',
            'name' => 'Spring Sale',
            'status' => MarketingCampaign::STATUS_ACTIVE,
        ]);

        $campaignProduct = CampaignProduct::create(array_merge([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
            'shopify_variant_id' => 5001,
            'product_title' => 'VIP Guardian Angel Reading',
        ], $campaignProductOverrides));

        CampaignProductResponse::create([
            'campaign_product_id' => $campaignProduct->id,
            'source' => CampaignProductResponse::SOURCE_MANUAL,
            'body' => 'Your predetermined reading text.',
        ]);

        return CampaignDelivery::create([
            'shopify_order_id' => 9001,
            'shopify_line_item_id' => 501,
            'campaign_product_id' => $campaignProduct->id,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Jane Doe',
            'status' => CampaignDelivery::STATUS_PENDING,
            'scheduled_at' => now(),
        ]);
    }

    public function test_renders_default_content_and_footer_with_product_title_interpolated(): void
    {
        $delivery = $this->delivery();

        $html = (new CampaignEmailMail($delivery))->render();

        $this->assertStringContainsString('VIP Guardian Angel Reading', $html);
        $this->assertStringContainsString('Your predetermined reading text.', $html);
        $this->assertStringContainsString('Just text SCOTT to 85358', $html);
        $this->assertStringContainsString('Motor Neurone Disease Association', $html);
    }

    public function test_renders_admin_authored_content_and_footer_with_placeholders(): void
    {
        $delivery = $this->delivery([
            'email_content' => 'Custom intro for {{ $productTitle }}.',
            'email_footer' => 'Custom footer for {{ $productTitle }}.',
        ]);

        $html = (new CampaignEmailMail($delivery))->render();

        $this->assertStringContainsString('Custom intro for VIP Guardian Angel Reading.', $html);
        $this->assertStringContainsString('Custom footer for VIP Guardian Angel Reading.', $html);
    }

    public function test_uses_the_uploaded_header_image_when_present(): void
    {
        $delivery = $this->delivery(['header_image' => 'banner.jpg']);

        $html = (new CampaignEmailMail($delivery))->render();

        $this->assertStringContainsString('campaign-header-images/banner.jpg', $html);
    }

    public function test_falls_back_to_the_site_logo_when_no_header_image_is_set(): void
    {
        config()->set('Site.logo', 'logo.png');
        $delivery = $this->delivery();

        $html = (new CampaignEmailMail($delivery))->render();

        $this->assertStringContainsString('configuration-images/logo.png', $html);
    }
}
