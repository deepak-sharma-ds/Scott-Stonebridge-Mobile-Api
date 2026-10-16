<?php

namespace Tests\Feature\Mail;

use App\Mail\EmailReadingMail;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Header\Headers;
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

    public function test_a_rich_text_body_renders_with_inline_styles_and_is_sanitized(): void
    {
        $delivery = $this->delivery();
        $delivery->update(['ai_response' => '<h2>Card One</h2><p>Spirit says <a href="https://example.com">hello</a>.</p><blockquote>The answer is yes.</blockquote><script>steal()</script><p onclick="x()">Tap</p>']);

        $html = (new EmailReadingMail($delivery->refresh()))->render();

        $this->assertStringContainsString('Card One</h2>', $html);
        $this->assertStringContainsString('margin:28px 0 12px', $html);
        $this->assertStringContainsString('font-family:Arial', $html);
        $this->assertStringNotContainsString('border-left', $html);
        $this->assertStringContainsString('The answer is yes.', $html);
        $this->assertStringNotContainsString('steal()', $html);
        $this->assertStringNotContainsString('onclick', $html);
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

    public function test_a_customer_supplied_name_cannot_inject_html_through_template_placeholders(): void
    {
        $delivery = $this->delivery(['email_footer' => 'Footer for {{ $customerName }}.']);
        $delivery->update(['customer_name' => '<a href="https://evil.example">Click</a><script>x()</script>']);

        $html = (new EmailReadingMail($delivery->refresh()))->render();

        $this->assertStringNotContainsString('<a href="https://evil.example">', $html);
        $this->assertStringNotContainsString('<script>x()</script>', $html);
        $this->assertStringContainsString('&lt;a href=', $html);
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

    private function sentHeaders(Mailable $mailable): Headers
    {
        Mail::to('buyer@example.com')->send($mailable);

        $messages = app('mailer')->getSymfonyTransport()->messages();

        return $messages->last()->getOriginalMessage()->getHeaders();
    }

    public function test_a_persisted_delivery_is_tagged_with_its_type_and_id_headers(): void
    {
        $delivery = $this->delivery();

        $headers = $this->sentHeaders(new EmailReadingMail($delivery));

        $this->assertSame('reading', $headers->get('X-Delivery-Type')->getBodyAsString());
        $this->assertSame((string) $delivery->id, $headers->get('X-Delivery-Id')->getBodyAsString());
    }

    public function test_an_unsaved_preview_delivery_is_not_tagged(): void
    {
        $this->delivery();
        $preview = EmailReadingDelivery::make(['customer_email' => 'x@example.com', 'customer_name' => 'Test', 'ai_response' => 'Body']);

        $headers = $this->sentHeaders(new EmailReadingMail($preview));

        $this->assertFalse($headers->has('X-Delivery-Type'));
        $this->assertFalse($headers->has('X-Delivery-Id'));
    }

    public function test_a_customer_email_embeds_the_hidden_tracking_pixel_for_its_token(): void
    {
        $delivery = $this->delivery();

        $html = (new EmailReadingMail($delivery))->render();

        $this->assertStringContainsString('<img src="'.route('email.track', $delivery->tracking_token).'"', $html);
        $this->assertStringContainsString('width="1" height="1"', $html);
    }

    public function test_an_unsaved_preview_delivery_embeds_no_tracking_pixel(): void
    {
        $this->delivery();

        $html = (new EmailReadingMail(EmailReadingDelivery::make(['customer_email' => 'x@example.com', 'ai_response' => 'Body'])))->render();

        $this->assertStringNotContainsString('/track/email/', $html);
    }

    public function test_untracked_rendering_of_a_saved_delivery_embeds_no_pixel_and_no_headers(): void
    {
        $delivery = $this->delivery();
        $mailable = new EmailReadingMail($delivery, tracked: false);

        $this->assertStringNotContainsString('/track/email/', $mailable->render());
        $this->assertFalse($this->sentHeaders($mailable)->has('X-Delivery-Type'));
    }
}
