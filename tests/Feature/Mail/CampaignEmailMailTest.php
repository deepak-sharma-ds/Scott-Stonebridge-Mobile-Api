<?php

namespace Tests\Feature\Mail;

use App\Mail\CampaignEmailMail;
use App\Models\CampaignDelivery;
use App\Models\CampaignProduct;
use App\Models\CampaignProductResponse;
use App\Models\MarketingCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Header\Headers;
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

    public function test_a_rich_text_response_renders_with_inline_styles_and_is_sanitized(): void
    {
        $delivery = $this->delivery();
        $delivery->campaignProduct->response->update([
            'body' => '<h2>Why this reading</h2><p>Spirit <a href="https://example.com">awaits</a>.</p><blockquote>Act now.</blockquote><script>steal()</script><p onclick="x()">Tap</p>',
        ]);

        $html = (new CampaignEmailMail($delivery->refresh()))->render();

        $this->assertStringContainsString('Why this reading</h2>', $html);
        $this->assertStringContainsString('margin:28px 0 12px', $html);
        $this->assertStringContainsString('font-family:Arial', $html);
        $this->assertStringNotContainsString('border-left', $html);
        $this->assertStringNotContainsString('steal()', $html);
        $this->assertStringNotContainsString('onclick', $html);
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

    private function sentHeaders(Mailable $mailable): Headers
    {
        Mail::to('buyer@example.com')->send($mailable);

        $messages = app('mailer')->getSymfonyTransport()->messages();

        return $messages->last()->getOriginalMessage()->getHeaders();
    }

    public function test_a_persisted_delivery_is_tagged_with_its_type_and_id_headers(): void
    {
        $delivery = $this->delivery();

        $headers = $this->sentHeaders(new CampaignEmailMail($delivery));

        $this->assertSame('campaign', $headers->get('X-Delivery-Type')->getBodyAsString());
        $this->assertSame((string) $delivery->id, $headers->get('X-Delivery-Id')->getBodyAsString());
    }

    public function test_an_unsaved_preview_delivery_is_not_tagged(): void
    {
        $this->delivery();
        $preview = CampaignDelivery::make(['customer_email' => 'x@example.com', 'customer_name' => 'Test']);

        $headers = $this->sentHeaders(new CampaignEmailMail($preview));

        $this->assertFalse($headers->has('X-Delivery-Type'));
        $this->assertFalse($headers->has('X-Delivery-Id'));
    }

    public function test_a_customer_email_embeds_the_hidden_tracking_pixel_for_its_token(): void
    {
        $delivery = $this->delivery();

        $html = (new CampaignEmailMail($delivery))->render();

        $this->assertStringContainsString('<img src="'.route('email.track', $delivery->tracking_token).'"', $html);
        $this->assertStringContainsString('width="1" height="1"', $html);
    }

    public function test_an_unsaved_preview_delivery_embeds_no_tracking_pixel(): void
    {
        $this->delivery();

        $html = (new CampaignEmailMail(CampaignDelivery::make(['customer_email' => 'x@example.com'])))->render();

        $this->assertStringNotContainsString('/track/email/', $html);
    }

    public function test_untracked_rendering_of_a_saved_delivery_embeds_no_pixel_and_no_headers(): void
    {
        $delivery = $this->delivery();
        $mailable = new CampaignEmailMail($delivery, tracked: false);

        $this->assertStringNotContainsString('/track/email/', $mailable->render());
        $this->assertFalse($this->sentHeaders($mailable)->has('X-Delivery-Type'));
    }
}
