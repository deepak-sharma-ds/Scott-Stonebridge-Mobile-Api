<?php

namespace Tests\Feature\Mail;

use App\Mail\EmailReadingMail;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailReadingMailTest extends TestCase
{
    use RefreshDatabase;

    private function delivery(array $productOverrides = []): EmailReadingDelivery
    {
        $product = EmailReadingProduct::create(array_merge([
            'shopify_product_id' => 111,
            'name' => 'VIP Guardian Angel Reading',
            'slug' => 'vip-guardian-angel-reading',
            'questions_schema' => [['key' => 'q1', 'label' => 'Question', 'required' => true]],
            'prompt_template' => 'Customer: {{ $customer_name }}.',
            'email_subject' => 'Your Reading',
        ], $productOverrides));

        return EmailReadingDelivery::create([
            'shopify_order_id' => 9001,
            'shopify_line_item_id' => 501,
            'email_reading_product_id' => $product->id,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Jane Doe',
            'questions' => ['q1' => 'What lies ahead?'],
            'ai_response' => 'Your predetermined reading text.',
            'status' => EmailReadingDelivery::STATUS_GENERATED,
        ]);
    }

    public function test_renders_default_content_and_footer_with_product_title_interpolated(): void
    {
        $delivery = $this->delivery();

        $html = (new EmailReadingMail($delivery))->render();

        $this->assertStringContainsString('VIP Guardian Angel Reading', $html);
        $this->assertStringContainsString('Your predetermined reading text.', $html);
        $this->assertStringContainsString('Just text SCOTT to 85358', $html);
        $this->assertStringContainsString('Motor Neurone Disease Association', $html);
    }

    public function test_renders_admin_authored_content_and_footer_with_placeholders(): void
    {
        $delivery = $this->delivery([
            'email_content' => 'Custom intro for {{ $productTitle }}.',
            'email_footer' => 'Custom footer for {{ $customerName }}.',
        ]);

        $html = (new EmailReadingMail($delivery))->render();

        $this->assertStringContainsString('Custom intro for VIP Guardian Angel Reading.', $html);
        $this->assertStringContainsString('Custom footer for Jane Doe.', $html);
    }

    public function test_uses_the_uploaded_header_image_when_present(): void
    {
        $delivery = $this->delivery(['header_image' => 'banner.jpg']);

        $html = (new EmailReadingMail($delivery))->render();

        $this->assertStringContainsString('reading-header-images/banner.jpg', $html);
    }

    public function test_falls_back_to_the_site_logo_when_no_header_image_is_set(): void
    {
        config()->set('Site.logo', 'logo.png');
        $delivery = $this->delivery();

        $html = (new EmailReadingMail($delivery))->render();

        $this->assertStringContainsString('configuration-images/logo.png', $html);
    }
}
